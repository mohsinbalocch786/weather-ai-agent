<?php

namespace Tests\Feature\Ai;

use App\Ai\Tools\GetCurrentWeather;
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

class GetCurrentWeatherTest extends TestCase
{
    private const GEOCODING = 'https://geocoding-api.open-meteo.com/*';

    private const FORECAST = 'https://api.open-meteo.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_it_returns_current_weather_for_a_city(): void
    {
        Http::fake([
            self::GEOCODING => Http::response(OpenMeteoFixtures::londonGeocoding()),
            self::FORECAST => Http::response(OpenMeteoFixtures::londonForecast()),
        ]);

        $result = json_decode($this->runTool(['city' => '  London ']), true);

        $this->assertSame('London', $result['location']['city']);
        $this->assertSame('United Kingdom', $result['location']['country']);
        $this->assertSame('GB', $result['location']['country_code']);
        $this->assertSame('Europe/London', $result['location']['timezone']);
        $this->assertSame(['value' => 12.4, 'unit' => '°C'], $result['current_weather']['temperature']);
        $this->assertSame(['value' => 81, 'unit' => '%'], $result['current_weather']['humidity']);
        $this->assertSame(['value' => 14.2, 'unit' => 'km/h'], $result['current_weather']['wind_speed']);
        $this->assertSame('Partly cloudy', $result['current_weather']['condition']);
        $this->assertTrue($result['current_weather']['is_day']);
        $this->assertSame('Open-Meteo', $result['source']);

        Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://geocoding-api.open-meteo.com/v1/search')
            && $request['name'] === 'London'
            && (int) $request['count'] === 1
            && ! isset($request['countryCode']));

        Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')
            && (float) $request['latitude'] === 51.50853
            && (float) $request['longitude'] === -0.12574
            && str_contains($request['current'], 'temperature_2m')
            && str_contains($request['current'], 'weather_code'));

        Http::assertSentCount(2);
    }

    public function test_it_passes_the_country_code_to_geocoding(): void
    {
        Http::fake([
            self::GEOCODING => Http::response(OpenMeteoFixtures::londonGeocoding()),
            self::FORECAST => Http::response(OpenMeteoFixtures::londonForecast()),
        ]);

        $this->runTool(['city' => 'London', 'country_code' => 'gb']);

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'geocoding-api')
            && $request['countryCode'] === 'GB');
    }

    public function test_it_reports_when_the_city_cannot_be_found(): void
    {
        Log::spy();

        Http::fake([
            self::GEOCODING => Http::response(['generationtime_ms' => 0.5]),
        ]);

        $result = $this->runTool(['city' => 'Atlantisville']);

        $this->assertStringContainsString('could not find a city named "Atlantisville"', $result);
        Http::assertSentCount(1);
        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message, array $context) => str_contains($message, 'city not found') && $context['city'] === 'Atlantisville'
        );
    }

    public function test_it_handles_geocoding_server_errors(): void
    {
        Log::spy();

        Http::fake([
            self::GEOCODING => Http::response('Service unavailable', 503),
        ]);

        $result = $this->runTool(['city' => 'London']);

        $this->assertSame('Unable to retrieve weather information right now. Please try again.', $result);
        Http::assertSentCount(2); // 5xx is retried once
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => str_contains($message, 'request failed') && $context['status'] === 503
        );
    }

    public function test_it_logs_the_open_meteo_reason_on_forecast_client_errors(): void
    {
        Log::spy();

        Http::fake([
            self::GEOCODING => Http::response(OpenMeteoFixtures::londonGeocoding()),
            self::FORECAST => Http::response(['error' => true, 'reason' => 'Latitude must be in range of -90 to 90°.'], 400),
        ]);

        $result = $this->runTool(['city' => 'London']);

        $this->assertSame('Unable to retrieve weather information right now. Please try again.', $result);
        Http::assertSentCount(2); // 4xx is not retried
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => $context['status'] === 400
                && $context['reason'] === 'Latitude must be in range of -90 to 90°.'
        );
    }

    public function test_it_handles_connection_failures(): void
    {
        Log::spy();

        Http::fake([
            self::GEOCODING => Http::failedConnection(),
        ]);

        $result = $this->runTool(['city' => 'London']);

        $this->assertStringContainsString('could not be reached', $result);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message) => str_contains($message, 'unreachable')
        );
    }

    public function test_it_handles_api_timeouts(): void
    {
        Log::spy();

        Http::fake([
            self::GEOCODING => Http::response(OpenMeteoFixtures::londonGeocoding()),
            self::FORECAST => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'),
        ]);

        $result = $this->runTool(['city' => 'London']);

        $this->assertSame('The weather service could not be reached right now. Please try again shortly.', $result);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => str_contains($context['error'], 'timed out')
        );
    }

    public function test_missing_and_null_optional_fields_are_reported_as_null(): void
    {
        $geocoding = OpenMeteoFixtures::londonGeocoding();
        unset($geocoding['results'][0]['admin1'], $geocoding['results'][0]['country'], $geocoding['results'][0]['country_code']);

        $forecast = OpenMeteoFixtures::londonForecast();
        unset($forecast['current_units'], $forecast['current']['is_day'], $forecast['current']['precipitation']);
        $forecast['current']['weather_code'] = null;
        $forecast['current']['wind_speed_10m'] = null;
        $forecast['current']['apparent_temperature'] = null;

        Http::fake([
            self::GEOCODING => Http::response($geocoding),
            self::FORECAST => Http::response($forecast),
        ]);

        $result = json_decode($this->runTool(['city' => 'London']), true);

        $this->assertNull($result['location']['region']);
        $this->assertNull($result['location']['country']);
        $this->assertSame('Unknown weather condition', $result['current_weather']['condition']);
        $this->assertNull($result['current_weather']['wind_speed']);
        $this->assertNull($result['current_weather']['feels_like']);
        $this->assertNull($result['current_weather']['precipitation']);
        $this->assertNull($result['current_weather']['is_day']);
        // Units fall back to Open-Meteo's metric defaults when current_units is missing.
        $this->assertSame(['value' => 12.4, 'unit' => '°C'], $result['current_weather']['temperature']);
    }

    public function test_it_handles_a_malformed_forecast_payload(): void
    {
        Log::spy();

        Http::fake([
            self::GEOCODING => Http::response(OpenMeteoFixtures::londonGeocoding()),
            self::FORECAST => Http::response(['latitude' => 51.5, 'longitude' => -0.12]),
        ]);

        $result = $this->runTool(['city' => 'London']);

        $this->assertSame('Weather information is currently unavailable.', $result);
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message) => str_contains($message, 'missing current data')
        );
    }

    public function test_unknown_weather_codes_are_described_as_unknown(): void
    {
        $forecast = OpenMeteoFixtures::londonForecast();
        $forecast['current']['weather_code'] = 42;

        Http::fake([
            self::GEOCODING => Http::response(OpenMeteoFixtures::londonGeocoding()),
            self::FORECAST => Http::response($forecast),
        ]);

        $result = json_decode($this->runTool(['city' => 'London']), true);

        $this->assertSame('Unknown weather condition', $result['current_weather']['condition']);
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
            'missing city' => [[]],
            'empty city' => [['city' => '']],
            'whitespace-only city' => [['city' => '   ']],
            'null city' => [['city' => null]],
            'overlong city' => [['city' => str_repeat('a', 101)]],
            'non-string city' => [['city' => ['London']]],
            'bad country code' => [['city' => 'London', 'country_code' => 'GBR']],
        ];
    }

    public function test_schema_marks_only_city_as_required_for_ollama(): void
    {
        $tool = app(GetCurrentWeather::class);

        // Mirrors Laravel\Ai\Gateway\Ollama\Concerns\MapsTools::mapTool().
        $schema = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();

        $this->assertSame(['city'], $schema['required']);
        $this->assertSame('string', $schema['properties']['city']['type']);
        $this->assertArrayHasKey('country_code', $schema['properties']);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function runTool(array $arguments): string
    {
        return (string) app(GetCurrentWeather::class)->handle(new Request($arguments));
    }
}
