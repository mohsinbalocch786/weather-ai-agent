# Weather AI Agent: guide for contributors and AI assistants

A Laravel 13 chat app. A Laravel AI SDK agent (`WeatherAssistant`) calls tools that query Open-Meteo, answered by a **small local Ollama model (`qwen2.5:3b`)**. Streaming Blade + Bootstrap chat UI, conversations saved per browser. See `README.md` for the full overview.

## Commands

```sh
composer test            # PHP suite (Xdebug is switched off by the script; see Gotchas)
composer test:js         # JavaScript unit tests (Node's built-in runner, no packages)
vendor/bin/pint --test app tests routes database   # code style check (drop --test to fix)
OLLAMA_LIVE_TESTS=true composer test -- --group=ollama   # opt-in: needs a running Ollama server
php artisan serve        # then open http://localhost:8000
```

Everything in `composer test` mocks external services, so it needs neither Ollama nor internet.

## Architecture

```
app/Ai/Agents/WeatherAssistant.php      the agent: instructions, 3 tools, history window, timeout
app/Ai/Tools/                           thin adapters for the model: validate input -> call a service -> map errors to messages
app/Ai/Tools/Concerns/InteractsWithOpenMeteo.php   shared schema, validation, error reporting + logging
app/Services/OpenMeteo/                 OpenMeteoClient (HTTP only) and WeatherCode (WMO code table)
app/Services/Weather/                   WeatherService (business logic), WeatherComparator (all comparison maths, in PHP)
app/Services/Chat/                      WeatherChat (SDK stream -> UI events), ChatVisitors (cookie identity), ConversationHistory, ToolLabel
app/Http/                               ChatController (thin), ChatMessageRequest
public/js/chat-core.js                  pure browser helpers (escaping, SSE parser); chat.js does the DOM
tests/Feature, tests/Unit, tests/js     PHP and JavaScript tests
```

Request flow: `POST /chat` -> `ChatController` -> `WeatherChat` -> `WeatherAssistant::stream()` -> tools -> `WeatherService` -> `OpenMeteoClient`. The browser receives Server-Sent Events: `text`, `tool`, `tool_done`, `error`, `conversation`, `done`.

## Rules for this project

- **Do not change the Ollama provider block in `config/ai.php`** (provider, model, URL handling) unless asked. Local overrides belong in `.env`.
- **No new dependencies** without a strong reason.
- **Verify SDK behaviour in `vendor/laravel/ai`** before using it; do not assume methods exist.
- **Mock all external HTTP in tests** (`Http::fake()` + `Http::preventStrayRequests()`). Fake agents with `WeatherAssistant::fake([...])`; a `Laravel\Ai\Responses\Data\ToolCall` in the list drives the real tool loop.
- Keep controllers thin; business logic lives in `app/Services`. Use constructor injection.
- Never put keys or server addresses in frontend code or in responses to the browser. Errors shown to users are friendly messages; details go to the log.
- Expected failures (unknown city, timeout, HTTP error) become short messages for the model. **Unexpected exceptions must propagate** so the SDK reports them.

## Gotchas

- **Xdebug** in `develop` mode crashes PHP (segfault) when an HTTP `ConnectionException` is thrown. `composer test` sets `XDEBUG_MODE=off`; if you run PHPUnit directly, do the same.
- The tool name the model sees is the **class basename** (`GetCurrentWeather`, `GetWeatherForecast`, `CompareCitiesWeather`). Renaming a class changes the prompt contract and `WeatherAssistant`'s instructions.
- A tool's parameter is only sent to Ollama as *required* if the schema calls `->required()`.
- Laravel 13 names the CSRF middleware `PreventRequestForgery`, and it skips itself in tests.
- `new WeatherAssistant` stores nothing; conversations are stored only after `forParticipant()` / `continue()` (the app does this in `WeatherChat`).
- A streamed response only runs, and so only stores the turn, when its body is read. Tests call `streamedContent()`.
- The 3B model sometimes picks the wrong tool even at temperature 0. Tool descriptions and the agent instructions are tuned for it; change them with the live tests.
- Forecasts are limited to 7 days and the agent timeout is 180 s because the small model is slow on long answers.
