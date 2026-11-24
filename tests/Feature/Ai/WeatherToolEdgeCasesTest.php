<?php

namespace Tests\Feature\Ai;

use App\Ai\Tools\CompareCitiesWeather;
use App\Ai\Tools\Concerns\InteractsWithOpenMeteo;
use App\Ai\Tools\GetCurrentWeather;
use App\Ai\Tools\GetWeatherForecast;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Tools\Request;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * Behaviour shared by all three tools that the per-tool tests don't reach.
 */
class WeatherToolEdgeCasesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    #[DataProvider('tools')]
    public function test_a_geocoding_result_without_coordinates_is_reported_as_unavailable(string $tool, array $arguments, string $expected, int $logged): void
    {
        Log::spy();
        Http::fake([
            'https://geocoding-api.open-meteo.com/*' => function ($request) {
                $geocoding = OpenMeteoFixtures::geocoding($request['name']);
                unset($geocoding['results'][0]['latitude']);

                return Http::response($geocoding);
            },
        ]);

        $this->assertSame($expected, $this->callTool($tool, $arguments));

        Http::assertSentCount(count($arguments['cities'] ?? [1])); // the forecast API is never called
        Log::shouldHaveReceived('error')->times($logged)->withArgs(
            fn (string $message, array $context) => $message === 'Weather tool: geocoding result missing coordinates'
        );
    }

    public static function tools(): array
    {
        return [
            'current weather' => [GetCurrentWeather::class, ['city' => 'London'], 'Weather information is currently unavailable.', 1],
            'forecast' => [GetWeatherForecast::class, ['city' => 'London'], 'Weather information is currently unavailable.', 1],
            'comparison' => [
                CompareCitiesWeather::class,
                ['cities' => ['London', 'Paris']],
                'Weather data is unavailable right now for: "London", "Paris". No comparison is possible.',
                2,
            ],
        ];
    }

    #[DataProvider('toolCalls')]
    public function test_unexpected_exceptions_are_not_swallowed(string $tool, array $arguments): void
    {
        Log::spy();
        $attempts = 0;
        Http::fake([
            'https://geocoding-api.open-meteo.com/*' => function () use (&$attempts) {
                $attempts++;

                throw new LogicException('a genuine bug');
            },
        ]);

        try {
            $this->callTool($tool, $arguments);
            $this->fail('Expected the LogicException to propagate to the SDK, which reports it to the model.');
        } catch (LogicException $e) {
            $this->assertSame('a genuine bug', $e->getMessage());
        }

        // Nothing was presented as an ordinary "weather unavailable" failure.
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        $this->assertSame(1, $attempts, 'A programming error must not be retried like a network failure.');
    }

    public static function toolCalls(): array
    {
        return [
            'current weather' => [GetCurrentWeather::class, ['city' => 'London']],
            'forecast' => [GetWeatherForecast::class, ['city' => 'London']],
            'comparison' => [CompareCitiesWeather::class, ['cities' => ['London', 'Paris']]],
        ];
    }

    public function test_the_failure_reporter_rethrows_exceptions_it_does_not_know(): void
    {
        $probe = new class
        {
            use InteractsWithOpenMeteo;

            public function report(Throwable $e): string
            {
                return $this->reportFailure($e, []);
            }
        };

        $this->expectException(LogicException::class);

        $probe->report(new LogicException('not an expected failure'));
    }

    public function test_places_without_an_id_are_deduplicated_by_coordinates(): void
    {
        Http::fake([
            'https://geocoding-api.open-meteo.com/*' => function ($request) {
                $geocoding = OpenMeteoFixtures::geocoding($request['name']);
                unset($geocoding['results'][0]['id']); // Open-Meteo normally sends one; fall back to lat/long

                return Http::response($geocoding);
            },
            'https://api.open-meteo.com/*' => fn ($request) => Http::response(
                OpenMeteoFixtures::current(OpenMeteoFixtures::cityAt($request['latitude']))
            ),
        ]);

        $result = json_decode($this->callTool(CompareCitiesWeather::class, ['cities' => ['London', 'Paris', 'London, GB']]), true);

        $this->assertCount(2, $result['cities']);
        $this->assertSame(
            ['"London" and "London, GB" refer to the same place (London, United Kingdom), so it is only included once.'],
            $result['notes'],
        );
    }

    public function test_tool_descriptions_tell_the_model_when_to_use_each_tool(): void
    {
        $current = (string) app(GetCurrentWeather::class)->description();
        $forecast = (string) app(GetWeatherForecast::class)->description();
        $compare = (string) app(CompareCitiesWeather::class)->description();

        $this->assertStringContainsString('RIGHT NOW in ONE city', $current);
        $this->assertStringContainsString('or to compare several cities', $current);
        $this->assertStringContainsString('FORECAST for ONE city for up to '.GetWeatherForecast::MAX_DAYS.' days', $forecast);
        $this->assertStringContainsString('tomorrow', $forecast);
        $this->assertStringContainsString('2 to '.CompareCitiesWeather::MAX_CITIES.' cities in a single call', $compare);
        $this->assertStringContainsString('"X vs Y"', $compare);
    }

    /**
     * @param  class-string  $tool
     * @param  array<string, mixed>  $arguments
     */
    private function callTool(string $tool, array $arguments): string
    {
        return (string) app($tool)->handle(new Request($arguments));
    }
}
