<?php

namespace Tests\Feature\Chat;

use App\Ai\Agents\WeatherAssistant;
use App\Services\Chat\ToolLabel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Responses\Data\ToolCall;
use RuntimeException;
use Tests\Feature\Ai\OpenMeteoFixtures;
use Tests\TestCase;

class ChatStreamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            'https://geocoding-api.open-meteo.com/*' => fn ($r) => Http::response(OpenMeteoFixtures::geocoding($r['name'])),
            'https://api.open-meteo.com/*' => fn ($r) => Http::response(
                OpenMeteoFixtures::current(OpenMeteoFixtures::cityAt($r['latitude']))
            ),
        ]);
    }

    public function test_it_streams_tool_activity_and_the_reply_as_server_sent_events(): void
    {
        WeatherAssistant::fake([
            new ToolCall('call_1', 'GetCurrentWeather', ['city' => 'London']),
            'It is 12.4°C and partly cloudy in London.',
        ])->preventStrayPrompts();

        $response = $this->postJson(route('chat.stream'), ['message' => '  Weather in London?  ']);

        $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        $events = $this->events($response);

        $this->assertSame(['type' => 'tool', 'name' => 'GetCurrentWeather', 'label' => 'Checking current weather in London'], $events[0]);
        $this->assertSame(['type' => 'tool_done', 'name' => 'GetCurrentWeather', 'successful' => true], $events[1]);
        $this->assertSame('It is 12.4°C and partly cloudy in London.', $this->text($events));
        $this->assertSame(['type' => 'done'], end($events));

        WeatherAssistant::assertPrompted('Weather in London?'); // trimmed
    }

    public function test_comparison_tool_activity_names_every_city(): void
    {
        WeatherAssistant::fake([
            new ToolCall('call_1', 'CompareCitiesWeather', ['cities' => ['London', 'Ahmedabad', 'Paris']]),
            'Ahmedabad is the warmest.',
        ]);

        $events = $this->events($this->postJson(route('chat.stream'), ['message' => 'Compare London, Ahmedabad and Paris']));

        $this->assertSame('Comparing London, Ahmedabad and Paris', $events[0]['label']);
        $this->assertTrue($events[1]['successful']);
    }

    public function test_raw_tool_payloads_and_internal_ids_are_not_sent_to_the_browser(): void
    {
        WeatherAssistant::fake([
            new ToolCall('call_secret_id', 'GetCurrentWeather', ['city' => 'London']),
            'Done.',
        ]);

        $body = $this->postJson(route('chat.stream'), ['message' => 'Weather in London?'])->streamedContent();

        $this->assertStringNotContainsString('call_secret_id', $body);
        $this->assertStringNotContainsString('latitude', $body);
        $this->assertStringNotContainsString('invocation_id', $body);
        $this->assertStringNotContainsString('Open-Meteo', $body);
    }

    public function test_an_unreachable_ollama_server_becomes_a_friendly_error_event(): void
    {
        Log::spy();
        WeatherAssistant::fake(fn () => throw ProviderConnectionException::forProvider('ollama'));

        $events = $this->events($this->postJson(route('chat.stream'), ['message' => 'Weather in London?']));

        $this->assertSame([[
            'type' => 'error',
            'message' => 'The AI model is not reachable right now. Please check that Ollama is running and try again.',
        ]], $events);

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => $message === 'Weather chat: stream failed'
                && $context['exception'] === ProviderConnectionException::class
        );
    }

    public function test_unexpected_failures_do_not_leak_exception_details(): void
    {
        Log::spy();
        WeatherAssistant::fake(fn () => throw new RuntimeException('SQLSTATE secret internals at C:\\wamp64\\www'));

        $body = $this->postJson(route('chat.stream'), ['message' => 'Hi'])->streamedContent();

        $this->assertStringContainsString('Something went wrong while answering. Please try again.', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('wamp64', $body);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_an_invalid_city_reaches_the_user_through_the_model(): void
    {
        WeatherAssistant::fake([
            new ToolCall('call_1', 'GetCurrentWeather', ['city' => 'Atlantisville']),
            fn (string $prompt) => 'I could not find Atlantisville. Could you check the spelling?',
        ]);

        $events = $this->events($this->postJson(route('chat.stream'), ['message' => 'Weather in Atlantisville?']));

        // The tool itself succeeds (it returns a "city not found" message for the model).
        $this->assertTrue($events[1]['successful']);
        $this->assertStringContainsString('could not find Atlantisville', $this->text($events));
    }

    public function test_the_message_is_required(): void
    {
        WeatherAssistant::fake()->preventStrayPrompts();

        $this->postJson(route('chat.stream'), ['message' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');

        WeatherAssistant::assertNeverPrompted();
    }

    public function test_the_message_length_is_limited(): void
    {
        WeatherAssistant::fake()->preventStrayPrompts();

        $this->postJson(route('chat.stream'), ['message' => str_repeat('a', 1001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');

        WeatherAssistant::assertNeverPrompted();
    }

    public function test_the_endpoint_is_protected_by_csrf_middleware(): void
    {
        // Laravel 13 CSRF/origin protection (PreventRequestForgery) skips itself in tests, so assert it is attached.
        app(Kernel::class); // registers the "web" middleware group on the router
        $route = app('router')->getRoutes()->getByName('chat.stream');
        $middleware = app('router')->gatherRouteMiddleware($route);

        $this->assertContains(PreventRequestForgery::class, $middleware);
    }

    public function test_the_endpoint_is_rate_limited(): void
    {
        WeatherAssistant::fake(array_fill(0, 25, 'ok'));

        for ($i = 0; $i < 20; $i++) {
            $this->postJson(route('chat.stream'), ['message' => 'Hi'])->assertOk();
        }

        $this->postJson(route('chat.stream'), ['message' => 'Hi'])->assertTooManyRequests();
    }

    public function test_tool_labels_fall_back_when_arguments_are_missing(): void
    {
        $this->assertSame('Checking current weather', ToolLabel::describe('GetCurrentWeather', []));
        $this->assertSame('Fetching the forecast for Paris', ToolLabel::describe('GetWeatherForecast', ['city' => ' Paris ']));
        $this->assertSame('Comparing cities', ToolLabel::describe('CompareCitiesWeather', ['cities' => 'London, Paris']));
        $this->assertSame('Comparing London and Paris', ToolLabel::describe('CompareCitiesWeather', ['cities' => ['London', 'Paris', 42]]));
        $this->assertSame('Looking that up', ToolLabel::describe('SomethingElse', []));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(TestResponse $response): array
    {
        $events = [];

        foreach (explode("\n\n", trim($response->streamedContent())) as $block) {
            if (str_starts_with($block, 'data: ')) {
                $events[] = json_decode(substr($block, 6), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $events;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function text(array $events): string
    {
        return implode('', array_column(array_filter($events, fn ($e) => $e['type'] === 'text'), 'delta'));
    }
}
