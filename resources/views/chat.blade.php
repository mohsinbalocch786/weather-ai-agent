<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $conversation ? $conversation->title.' · ' : '' }}Weather AI Agent</title>

    {{-- Applied before paint so dark-mode users don't see a light flash. --}}
    <script>
        document.documentElement.dataset.bsTheme =
            window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    </script>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+"
          crossorigin="anonymous">
    <link rel="stylesheet" href="{{ asset('css/chat.css') }}">
</head>
<body class="bg-body-tertiary">
<div class="chat-shell d-flex mx-auto">

    {{-- Conversation list: a fixed column on large screens, a slide-in panel on small ones. --}}
    <aside class="offcanvas-lg offcanvas-start sidebar border-end bg-body" tabindex="-1" id="sidebar" aria-labelledby="sidebar-title">
        <div class="offcanvas-header border-bottom py-2">
            <h2 class="offcanvas-title h6 mb-0" id="sidebar-title">Conversations</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column p-2">
            <a href="{{ route('chat') }}" class="btn btn-primary btn-sm mb-2">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New chat
            </a>
            <nav id="conversation-list" class="list-group list-group-flush overflow-auto" aria-label="Saved conversations">
                @forelse ($conversations as $item)
                    <a href="{{ route('conversations.show', $item->id) }}"
                       class="list-group-item list-group-item-action border-0 rounded px-2 py-2 @if ($conversation?->id === $item->id) active @endif"
                       data-conversation-id="{{ $item->id }}"
                       @if ($conversation?->id === $item->id) aria-current="page" @endif>
                        <div class="text-truncate small fw-medium">{{ $item->title }}</div>
                        <small class="opacity-75">{{ $item->updated_at?->diffForHumans() }}</small>
                    </a>
                @empty
                    <p id="no-conversations" class="small text-body-secondary px-2 mb-0">Your conversations will appear here.</p>
                @endforelse
            </nav>
        </div>
    </aside>

    <div class="chat-app d-flex flex-column flex-grow-1 min-w-0">
        <header class="d-flex align-items-center gap-2 px-3 py-2 border-bottom bg-body">
            <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button"
                    data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Show conversations">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
            <i class="bi bi-cloud-sun-fill fs-3 text-warning" aria-hidden="true"></i>
            <div class="me-auto min-w-0">
                <h1 class="h6 mb-0 text-truncate" id="chat-title">{{ $conversation?->title ?? 'Weather AI Agent' }}</h1>
                <small class="text-body-secondary">
                    Local model <span class="badge text-bg-secondary">{{ $model }}</span> · data from Open-Meteo
                </small>
            </div>
            @if ($conversation)
                <form method="post" action="{{ route('conversations.destroy', $conversation->id) }}"
                      onsubmit="return confirm('Delete this conversation?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Delete conversation">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                    </button>
                </form>
            @endif
        </header>

        @if (session('status'))
            <div class="alert alert-success alert-dismissible fade show rounded-0 mb-0 py-2 small" role="status">
                {{ session('status') }}
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <main id="messages" class="flex-grow-1 overflow-auto px-3 py-4" aria-live="polite" aria-label="Conversation">
            @forelse ($messages as $message)
                @if ($message['role'] === 'user')
                    <div class="msg msg-user d-flex justify-content-end mb-3">
                        <div class="bubble bg-primary text-white">{{ $message['content'] }}</div>
                    </div>
                @else
                    <div class="msg msg-assistant done d-flex gap-2 mb-3">
                        <div class="avatar flex-shrink-0"><i class="bi bi-robot" aria-hidden="true"></i></div>
                        <div class="flex-grow-1 min-w-0">
                            @if ($message['tools'])
                                <div class="tools d-flex flex-wrap gap-1 mb-1">
                                    @foreach ($message['tools'] as $label)
                                        <span class="badge rounded-pill text-bg-light border tool-chip">
                                            <i class="bi bi-check-circle-fill text-success me-1" aria-hidden="true"></i>{{ $label }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                            @if ($message['content'] !== '')
                                <div class="bubble bg-body border"><span class="text" data-markdown>{{ $message['content'] }}</span></div>
                            @endif
                            @if ($message['failed'])
                                <div class="alert alert-danger py-2 px-3 mt-2 mb-0 small" role="alert">This reply did not finish.</div>
                            @endif
                        </div>
                    </div>
                @endif
            @empty
                <section id="welcome" class="text-center text-body-secondary my-auto">
                    <i class="bi bi-chat-dots display-5 d-block mb-2" aria-hidden="true"></i>
                    <p class="mb-3">Ask about the weather anywhere: current conditions, forecasts, or comparisons.</p>
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary suggestion">What is the weather in Ahmedabad right now?</button>
                        <button type="button" class="btn btn-sm btn-outline-primary suggestion">Will it rain in London tomorrow?</button>
                        <button type="button" class="btn btn-sm btn-outline-primary suggestion">Is it warmer in Mumbai or Paris?</button>
                    </div>
                </section>
            @endforelse
        </main>

        <footer class="border-top bg-body px-3 py-3">
            <form id="chat-form" action="{{ route('chat.stream') }}" method="post" class="d-flex gap-2 align-items-end" novalidate>
                <input type="hidden" name="conversation" id="conversation" value="{{ $conversation?->id }}">
                <label for="message" class="visually-hidden">Your message</label>
                <textarea id="message" name="message" class="form-control" rows="1"
                          maxlength="{{ $maxLength }}" placeholder="Ask about the weather…"
                          autocomplete="off" required></textarea>
                <button type="submit" id="send" class="btn btn-primary" aria-label="Send">
                    <i class="bi bi-send-fill" aria-hidden="true"></i>
                </button>
                <button type="button" id="stop" class="btn btn-outline-danger d-none" aria-label="Stop">
                    <i class="bi bi-stop-fill" aria-hidden="true"></i>
                </button>
            </form>
            <small class="text-body-secondary d-block mt-1">
                Enter to send · Shift+Enter for a new line · Conversations are saved in this browser.
            </small>
        </footer>
    </div>
</div>

<template id="tpl-user">
    <div class="msg msg-user d-flex justify-content-end mb-3">
        <div class="bubble bg-primary text-white"></div>
    </div>
</template>

<template id="tpl-assistant">
    <div class="msg msg-assistant d-flex gap-2 mb-3">
        <div class="avatar flex-shrink-0"><i class="bi bi-robot" aria-hidden="true"></i></div>
        <div class="flex-grow-1 min-w-0">
            <div class="tools d-flex flex-wrap gap-1 mb-1"></div>
            <div class="bubble bg-body border">
                <span class="text"></span><span class="typing" aria-label="Thinking"><span></span><span></span><span></span></span>
            </div>
        </div>
    </div>
</template>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
<script src="{{ asset('js/chat-core.js') }}"></script>
<script src="{{ asset('js/chat.js') }}"></script>
</body>
</html>
