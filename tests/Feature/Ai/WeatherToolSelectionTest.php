<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\WeatherAssistant;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Live evaluation: does the real Ollama model pick the right tool?
 *
 * A faked gateway can't answer that, so these tests talk to the Ollama server
 * configured in config/ai.php. Open-Meteo is still faked, so results are
 * deterministic and no weather API traffic is generated.
 *
 * Skipped by default. Run with:
 *   OLLAMA_LIVE_TESTS=true php artisan test --group=ollama
 */
#[Group('ollama')]
class WeatherToolSelectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('OLLAMA_LIVE_TESTS', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set OLLAMA_LIVE_TESTS=true to run live tool-selection tests against Ollama.');
        }

        // Only Open-Meteo is faked; unmatched requests (Ollama) go to the real server.
        Http::fake([
            'https://geocoding-api.open-meteo.com/*' => fn ($request) => Http::response(OpenMeteoFixtures::geocoding($request['name'])),
            'https://api.open-meteo.com/*' => fn ($request) => Http::response(
                isset($request['daily'])
                    ? OpenMeteoFixtures::londonDaily(min(7, (int) $request['forecast_days']))
                    : OpenMeteoFixtures::current(OpenMeteoFixtures::cityAt($request['latitude']) ?? 'london')
            ),
        ]);
    }

    #[DataProvider('questions')]
    public function test_the_model_chooses_the_right_tool(string $question, string $expectedTool): void
    {
        // Uses the agent's own #[Timeout], so a slow answer fails here exactly as it would in the app.
        $startedAt = microtime(true);
        $response = (new WeatherAssistant)->prompt($question);
        $seconds = round(microtime(true) - $startedAt, 1);

        file_put_contents(
            storage_path('logs/tool-selection-timings.log'),
            sprintf('%s	%ss	%s	%s
', now()->toDateTimeString(), $seconds, $expectedTool, $question),
            FILE_APPEND,
        );

        $called = $response->toolCalls->pluck('name')->unique()->values()->all();

        $this->assertContains($expectedTool, $called, sprintf(
            'Expected %s for "%s", model called: [%s]. Answer: %s',
            $expectedTool, $question, implode(', ', $called), $response->text,
        ));

        foreach (array_diff(['GetCurrentWeather', 'GetWeatherForecast', 'CompareCitiesWeather'], [$expectedTool]) as $other) {
            $this->assertNotContains($other, $called, "Model also called {$other} for \"{$question}\".");
        }
    }

    public static function questions(): array
    {
        return [
            'now' => ['What is the weather in London right now?', 'GetCurrentWeather'],
            'currently' => ['How hot is it in London currently?', 'GetCurrentWeather'],
            'plain' => ['Weather in London?', 'GetCurrentWeather'],
            'is it raining' => ['Is it raining in London at the moment?', 'GetCurrentWeather'],
            'tomorrow' => ['What will the weather be like in London tomorrow?', 'GetWeatherForecast'],
            'will it rain' => ['Will it rain in London tomorrow?', 'GetWeatherForecast'],
            'this week' => ['Give me the forecast for London this week.', 'GetWeatherForecast'],
            'next days' => ['How will the weather be in London over the next 5 days?', 'GetWeatherForecast'],
            'warmer or' => ['Is it warmer in London or Ahmedabad right now?', 'CompareCitiesWeather'],
            'compare two' => ['Compare the weather in London and Paris.', 'CompareCitiesWeather'],
            'versus three' => ['London vs Paris vs Rome weather', 'CompareCitiesWeather'],
        ];
    }
}
