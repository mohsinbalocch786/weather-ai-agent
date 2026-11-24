/**
 * Weather AI Agent chat client.
 *
 * Sends the message with fetch() and reads the Server-Sent Events reply
 * incrementally (EventSource cannot POST). Event types from the server:
 *   text {delta} · tool {name,label} · tool_done {name,successful} · error {message}
 *   conversation {id,title,url} (after the turn is stored) · done
 */
(() => {
    'use strict';

    const form = document.getElementById('chat-form');
    const input = document.getElementById('message');
    const sendBtn = document.getElementById('send');
    const stopBtn = document.getElementById('stop');
    const messages = document.getElementById('messages');
    const conversationInput = document.getElementById('conversation');
    const conversationList = document.getElementById('conversation-list');
    const chatTitle = document.getElementById('chat-title');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    let controller = null;

    // ---- rendering -------------------------------------------------------

    const { render, createEventParser } = window.ChatCore;

    const scrollToBottom = () => { messages.scrollTop = messages.scrollHeight; };

    const addUserMessage = (text) => {
        const node = document.getElementById('tpl-user').content.firstElementChild.cloneNode(true);
        node.querySelector('.bubble').textContent = text;
        messages.append(node);
        scrollToBottom();
    };

    const addAssistantMessage = () => {
        const node = document.getElementById('tpl-assistant').content.firstElementChild.cloneNode(true);
        messages.append(node);
        scrollToBottom();

        let raw = '';
        const textEl = node.querySelector('.text');
        const toolsEl = node.querySelector('.tools');
        const chips = new Map();

        return {
            append(delta) {
                raw += delta;
                textEl.innerHTML = render(raw);
                scrollToBottom();
            },
            toolStarted(name, label) {
                const chip = document.createElement('span');
                chip.className = 'badge rounded-pill text-bg-light border tool-chip';
                chip.innerHTML = '<span class="spinner-border me-1" role="status" aria-hidden="true"></span>';
                chip.append(document.createTextNode(label));
                toolsEl.append(chip);
                chips.set(name, chip);
                scrollToBottom();
            },
            toolFinished(name, successful) {
                const chip = chips.get(name);
                if (!chip) return;
                chip.querySelector('.spinner-border')?.remove();
                const icon = document.createElement('i');
                icon.className = `bi ${successful ? 'bi-check-circle-fill text-success' : 'bi-exclamation-circle-fill text-danger'} me-1`;
                chip.prepend(icon);
            },
            error(message) {
                const alert = document.createElement('div');
                alert.className = 'alert alert-danger py-2 px-3 mt-2 mb-0 small';
                alert.setAttribute('role', 'alert');
                alert.textContent = message;
                node.querySelector('.bubble').after(alert);
                if (!raw) node.querySelector('.bubble').classList.add('d-none');
                this.finish();
            },
            finish(note) {
                node.classList.add('done');
                if (note) {
                    const small = document.createElement('div');
                    small.className = 'small text-body-secondary mt-1';
                    small.textContent = note;
                    node.querySelector('.bubble').after(small);
                }
                scrollToBottom();
            },
        };
    };

    /** Remember the stored conversation: URL, title and sidebar entry. */
    const adoptConversation = ({ id, title, url }) => {
        const isNew = conversationInput.value !== id;
        conversationInput.value = id;

        if (url && window.location.href !== url) {
            window.history.replaceState({ conversation: id }, '', url);
        }
        if (title) {
            chatTitle.textContent = title;
            document.title = `${title} · Weather AI Agent`;
        }

        let item = conversationList.querySelector(`[data-conversation-id="${CSS.escape(id)}"]`);
        if (!item) {
            item = document.createElement('a');
            item.className = 'list-group-item list-group-item-action border-0 rounded px-2 py-2';
            item.dataset.conversationId = id;
            item.href = url;
            const name = document.createElement('div');
            name.className = 'text-truncate small fw-medium';
            name.textContent = title || 'New conversation';
            const when = document.createElement('small');
            when.className = 'opacity-75';
            when.textContent = 'just now';
            item.append(name, when);
        } else {
            item.querySelector('small').textContent = 'just now';
        }

        if (isNew) {
            conversationList.querySelectorAll('.active').forEach((el) => {
                el.classList.remove('active');
                el.removeAttribute('aria-current');
            });
            item.classList.add('active');
            item.setAttribute('aria-current', 'page');
        }

        document.getElementById('no-conversations')?.remove();
        conversationList.prepend(item); // most recently updated first
    };

    const setBusy = (busy) => {
        input.disabled = busy;
        sendBtn.classList.toggle('d-none', busy);
        stopBtn.classList.toggle('d-none', !busy);
        if (!busy) input.focus();
    };

    // ---- networking ------------------------------------------------------

    const httpError = async (response) => {
        if (response.status === 422) {
            const body = await response.json().catch(() => ({}));
            return body.message || 'Please check your message.';
        }
        if (response.status === 429) return 'Too many messages. Please wait a minute and try again.';
        if (response.status === 419) return 'Your session expired. Please reload the page.';
        return `The server returned an error (${response.status}). Please try again.`;
    };

    const send = async (text) => {
        document.getElementById('welcome')?.remove();
        addUserMessage(text);
        const reply = addAssistantMessage();
        setBusy(true);
        controller = new AbortController();

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/event-stream, application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ message: text, conversation: conversationInput.value || null }),
                signal: controller.signal,
            });

            if (!response.ok || !response.body) {
                reply.error(await httpError(response));
                return;
            }

            await readEvents(response.body, (event) => {
                switch (event.type) {
                    case 'text': reply.append(event.delta); break;
                    case 'tool': reply.toolStarted(event.name, event.label); break;
                    case 'tool_done': reply.toolFinished(event.name, event.successful); break;
                    case 'error': reply.error(event.message); break;
                    case 'conversation': adoptConversation(event); break;
                    case 'done': reply.finish(); break;
                }
            });
            reply.finish();
        } catch (e) {
            if (e.name === 'AbortError') reply.finish('Stopped.');
            else reply.error('Connection lost. Please try again.');
        } finally {
            controller = null;
            setBusy(false);
        }
    };

    /** Read an SSE response body and pass each parsed event to onEvent. */
    const readEvents = async (body, onEvent) => {
        const reader = body.pipeThrough(new TextDecoderStream()).getReader();
        const parser = createEventParser(onEvent);

        for (;;) {
            const { value, done } = await reader.read();
            if (done) break;
            parser.push(value);
        }
    };

    // ---- input handling --------------------------------------------------

    const autoGrow = () => {
        input.style.height = 'auto';
        input.style.height = `${input.scrollHeight}px`;
    };

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const text = input.value.trim();
        if (!text || controller) return;
        input.value = '';
        autoGrow();
        send(text);
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            form.requestSubmit();
        }
    });

    input.addEventListener('input', autoGrow);

    stopBtn.addEventListener('click', () => controller?.abort());

    document.querySelectorAll('.suggestion').forEach((btn) => {
        btn.addEventListener('click', () => {
            input.value = btn.textContent.trim();
            form.requestSubmit();
        });
    });

    // Saved messages arrive as escaped text from the server; apply the same rendering as live replies.
    document.querySelectorAll('[data-markdown]').forEach((el) => { el.innerHTML = render(el.textContent); });

    scrollToBottom();
    input.focus();
})();
