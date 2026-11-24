/**
 * Pure helpers for the chat page (no DOM access), kept in their own classic
 * script so they can be unit-tested without a browser: the tests load this exact
 * file in Node's `vm` (see tests/js/). Exposes `ChatCore` on the global object.
 */
(function (global) {
    'use strict';

    const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

    const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (c) => ESCAPES[c]);

    /**
     * Model output -> safe HTML.
     * Everything is escaped first; only **bold** is then turned into <strong>,
     * so the result can never contain any other tag or attribute.
     */
    const render = (text) => escapeHtml(text).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

    /**
     * Incremental Server-Sent Events parser.
     *
     * Feed it decoded text chunks of any size with push(); it calls onEvent with
     * the parsed JSON of every complete "data: {json}" block (blocks end with a
     * blank line). Comments, other fields and malformed JSON are ignored, and an
     * incomplete trailing block is held back until the rest arrives.
     */
    const createEventParser = (onEvent) => {
        let buffer = '';

        return {
            push(chunk) {
                // Normalise CRLF; a lone trailing "\r" waits for its "\n" in the next chunk.
                buffer = (buffer + chunk).replace(/\r\n/g, '\n');

                let boundary;
                while ((boundary = buffer.indexOf('\n\n')) !== -1) {
                    const block = buffer.slice(0, boundary);
                    buffer = buffer.slice(boundary + 2);

                    for (const line of block.split('\n')) {
                        if (!line.startsWith('data: ')) continue;
                        try { onEvent(JSON.parse(line.slice(6))); } catch { /* ignore malformed line */ }
                    }
                }
            },
        };
    };

    global.ChatCore = { escapeHtml, render, createEventParser };
})(globalThis);
