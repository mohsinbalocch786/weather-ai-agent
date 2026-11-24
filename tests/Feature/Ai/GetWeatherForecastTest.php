<?php

namespace Tests\Feature\Ai;

use App\Ai\Tools\GetWeatherForecast;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GetWeatherForecastTest extends TestCase
{
    private const GEOCODING = 'https://geocoding-api.open-meteo.com/*';

    private const FORECAST = 'https://api.open-meteo.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_it_returns_a_daily_forecast(): void
    {
        Http::fake([
            self::GEOCODING => Http::response(OpenMeteoFixtures::londonGeocoding()),
            self::FORECAST => Http::response(OpenMeteoFixtures::londonDaily(3)),
        ]);

        $result = json_decode($this->runTool(['city' => 'London', 'days' => 3]), true);

        $this->assertSame('London', $result['location']['city']);
        $this->assertSame('United Kingdom', $result['location']['country']);
        $this->assertSame('Europe/London', $result['location']['timezone']);
        $this->assertSame([
            'temperature' => '°C',
            'precipitation_sum' => 'mm',
            'precipitation_probability_max' => '%',
            'wind_speed' => 'km/h',
        ], $result['units']);

        $this->assertCount(3, $result['forecast']);
        $this->assertSame(['Today', 'Tomorrow', 'Thursday'], array_column($result['forecast'], 'label'));

        $tomorrow = $result['forecast'][1];
        $this->assertSame('2026-11-25', $tomorrow['date']);
        $this->assertSame('Slight rain', $tomorrow['condition']);
        $this->assertSame(11.4, $tomorrow['temperature_max']);
        $this->assertEquals(6.0, $tomorrow['temperature_min']); // fake JSON encoding drops the .0
        $this->assertSame(85, $tomorrow['precipitation_probability_max']);
        $this->assertSame(6.4, $tomorrow['precipitation_sum']);
        $this->assertEquals(27.0, $tomorrow['wind_speed_max']);
        $this->assertSame('2026-11-25T07:30', $tomorrow['sunrise']);
    }

    public function test_it_sends_the_expected_open_meteo_parameters(): void
    {
        Http::fake([
            self::GEOCODING => Http::response(OpenMeteoFixtures::londonGeocoding()),
            self::FORECAST => Http::response(OpenMeteoFixtures::londonDaily(7)),
        ]);

        $this->runTool(['city' => 'London', 'country_code' => 'gb', 'days' => 7]);

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'geocoding-api')
            && $request['name'] === 'London'
            && $request['countryCode'] === 'GB');

        Http::assertSent(function (HttpRequest $request) {
            if (! str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')) {
                return false;
            }

            $daily = explode(',', $request['daily']);

            return (float) $request['latitude'] === 51.50853
                && (int) $request['forecast_days'] === 7
                && $request['timezone'] === 'auto'
                && ! isset($request['current'])
                && in_array('temperature_2m_max', $daily, true)
                && in_array('precipitation_probability_max', $daily, true)
                && in_array('weather_code', $daily, true);
        });
    }

    public function test_it_defaults_to_three_days(): void
    {
        Http::fake([
            self::GEOCODING => Http::response(OpenMeteoFixtures::londonGeocoding()),
            self::FORECAST => Http::response(OpenMeteoFixtures::londonDaily(3)),
        ]);

        $this->runTool(['city' => 'London']);

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'api.open-meteo.com/v1/forecast')
            && (int) $request['forecast_days'] === GetWeatherForecast::DEFAULT_DAYS);
    }

    public function test_it_accepts_days_as_a_numeric_string_from_the_model(): void
    {
        Http::fake([
            self::GEOCODING => Http::response(OpenMeteoFixtures::londonGeocoding()),
            self::FORECAST => Http::response(OpenMeteoFixtures::londonDaily(2)),
        ]);

        $result = json_decode($this->runTool(['city' => 'London', 'days' => '2']), true);

        $this->assertCount(2, $result['forecast']);
        Http::assertSent(fn (HttpRequest $request) => isset($request['forecast_days']) && (int) $request['forecast_days'] === 2);
    }

    public function test_it_reports_an_invalid_city_name(): void
    {
        Log::spy();

        Http::fake([
            self::GEOCODING => Http::response(['generationtime_ms' => 0.1]),
        ]);

        $result = $this->runTool(['city' => 'Xqzzyvillex', 'days' => 5]);

        $this->assertStringContainsString('could not find a city named "Xqzzyvillex"', $result);
        Http::assertSentCount(1);
        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message, array $context) => $context['tool'] === 'GetWeatherForecast'
                && $context['city'] === 'Xqzzyvillex'
                && $context['days'] === 5
        );
    }

    public function test_it_handles_api_timeouts(): void
    {
        Log::spy();

        Http::fake([
            self::GEOCODING => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'),
        ]);

        $result = $this->runTool(['city' => 'London']);

        $this->assertSame('The weather service could not be reached right now. Please try again shortly.', $result);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['tool'] === 'GetWeatherForecast'
                && str_contains($context['error'], 'timed out')
        );
    }

    public function test_it_logs_the_open_meteo_reason_on_client_errors(): void
    {
        Log::spy();

        Http::fake([
            self::GEOCODING => Http::response(OpenMeteoFixtures::londonGeocoding()),
            self::FORECAST => Http::response(['reason' => 'Forecast days is invalid. Allowed range 0 to 16. Given 17.', 'error' => true], 400),
        ]);

        $result = $this->runTool(['city' => 'London']);

        $this->assertSame('Unable to retrieve weather information right now. Please try again.', $result);
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => $context['status'] === 400
                && str_contains($context['reason'], 'Forecast days is invalid')
        );
    }

    public function test_null_values_and_ragged_arrays_become_null_instead_of_failing(): void
    {
        $forecast = OpenMeteoFixtures::londonDaily(3);
        $forecast['daily']['precipitation_probability_max'] = [10, null, null];
        $forecast['daily']['weather_code'] = [2, null, 3];
        $forecast['daily']['wind_speed_10m_max'] = [18.4]; // shorter than "time"
        unset($forecast['daily']['sunrise'], $forecast['daily_units']);
        $forecast['daily']['sunset'] = 'not-an-array';

        Http::fake([
            self::GEOCODING => Http::response(OpenMeteoFixtures::londonGeocoding()),
            self::FORECAST => Http::response($forecast),
        ]);

        $result = json_decode($this->runTool(['city' => 'London']), true);

        $this->assertCount(3, $result['forecast']);
        $this->assertNull($result['forecast'][1]['precipitation_probability_max']);
        $this->assertSame('Unknown weather condition', $result['forecast'][1]['condition']);
        $this->assertSame(18.4, $result['forecast'][0]['wind_speed_max']);
        $this->assertNull($result['forecast'][2]['wind_speed_max']);
        $this->assertNull($result['forecast'][0]['sunrise']);
        $this->assertNull($result['forecast'][0]['sunset']);
        $this->assertSame('°C', $result['units']['temperature']); // default when daily_units is missing
    }

    /**
     * @param  array<string, mixed>  $daily
     */
    #[DataProvider('missingDailyData')]
    public function test_missing_daily_data_is_reported_as_unavailable(mixed $daily): void
    {
        Log::spy();

        $forecast = OpenMeteoFixtures::londonDaily(3);
        $forecast['daily'] = $daily;

        Http::fake([
            self::GEOCODING => Http::response(OpenMeteoFixtures::londonGeocoding()),
            self::FORECAST => Http::response(array_filter($forecast, fn ($value) => $value !== null)),
        ]);

        $this->assertSame('Weather forecast information is currently unavailable.', $this->runTool(['city' => 'London']));
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message) => str_contains($message, 'missing daily data')
        );
    }

    public static function missingDailyData(): array
    {
        return [
            'no daily key' => [null],
            'no time array' => [['temperature_2m_max' => [10.0]]],
            'empty time array' => [['time' => []]],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('invalidArguments')]
    public function test_invalid_arguments_raise_a_validation_error_for_the_model(array $arguments): void
    {
        Http::fake();

        $this->expectException(ValidationException::class);

        try {
            $this->runTool($arguments);
        } finally {
            Http::assertNothingSent();
        }
    }

    public static function invalidArguments(): array
    {
        return [
            'missing city' => [['days' => 3]],
            'whitespace-only city' => [['city' => '  ']],
            'zero days' => [['city' => 'London', 'days' => 0]],
            'too many days' => [['city' => 'London', 'days' => 8]],
            'fractional days' => [['city' => 'London', 'days' => 2.5]],
            'non-numeric days' => [['city' => 'London', 'days' => 'tomorrow']],
            'bad country code' => [['city' => 'London', 'country_code' => 'GBR']],
        ];
    }

    public function test_schema_matches_what_ollama_receives(): void
    {
        // Mirrors Laravel\Ai\Gateway\Ollama\Concerns\MapsTools::mapTool().
        $schema = (new ObjectSchema(app(GetWeatherForecast::class)->schema(new JsonSchemaTypeFactory)))->toSchema();

        $this->assertSame(['city'], $schema['required']);
        $this->assertSame('integer', $schema['properties']['days']['type']);
        $this->assertSame(1, $schema['properties']['days']['minimum']);
        $this->assertSame(7, $schema['properties']['days']['maximum']);
        $this->assertSame('string', $schema['properties']['country_code']['type']);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function runTool(array $arguments): string
    {
        return (string) app(GetWeatherForecast::class)->handle(new Request($arguments));
    }
}
