<?php

namespace Tests\Feature\Ai;

use App\Ai\Tools\CompareCitiesWeather;
use Closure;
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

class CompareCitiesWeatherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_it_compares_two_cities(): void
    {
        $this->fakeOpenMeteo();

        $result = $this->compare(['London', 'Ahmedabad']);

        $this->assertSame(['London', 'Ahmedabad'], array_column(array_column($result['cities'], 'location'), 'city'));

        $comparison = $result['comparison'];
        $this->assertSame(['city' => 'Ahmedabad', 'value' => 30.8, 'unit' => '°C'], $comparison['warmest']);
        $this->assertSame(['city' => 'London', 'value' => 12.4, 'unit' => '°C'], $comparison['coldest']);
        $this->assertSame(['value' => 18.4, 'unit' => '°C'], $comparison['temperature_difference']);
        $this->assertSame('London', $comparison['most_humid']['city']);
        $this->assertSame('London', $comparison['windiest']['city']);
        $this->assertSame(
            'Ahmedabad (30.8°C) is 18.4°C warmer than London (12.4°C). '
            .'London is more humid (81% vs 22%). '
            .'London is windier (14.2 vs 5.3 km/h). '
            .'Conditions: London: partly cloudy, Ahmedabad: clear sky.',
            $comparison['summary'],
        );

        $this->assertSame([], $result['not_found']);
        $this->assertSame([], $result['unavailable']);
        $this->assertSame('Open-Meteo', $result['source']);
        Http::assertSentCount(4); // geocoding + current for each city
    }

    public function test_it_ranks_three_cities(): void
    {
        $this->fakeOpenMeteo();

        $result = $this->compare(['London', 'Paris', 'Rome']);

        $this->assertCount(3, $result['cities']);
        $this->assertSame('Rome', $result['comparison']['warmest']['city']);
        $this->assertSame('Paris', $result['comparison']['coldest']['city']);
        $this->assertSame(6.6, $result['comparison']['temperature_difference']['value']);
        $this->assertStringStartsWith(
            'Rome is the warmest at 16.2°C and Paris is the coldest at 9.6°C, a difference of 6.6°C. Paris is the most humid (87%).',
            $result['comparison']['summary'],
        );
    }

    public function test_a_country_code_suffix_is_sent_to_geocoding(): void
    {
        $this->fakeOpenMeteo();

        $this->compare(['Paris, fr', 'London, GB']);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'geocoding-api') && $r['name'] === 'Paris' && $r['countryCode'] === 'FR');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'geocoding-api') && $r['name'] === 'London' && $r['countryCode'] === 'GB');
    }

    public function test_a_trailing_country_name_is_dropped_instead_of_breaking_geocoding(): void
    {
        $this->fakeOpenMeteo();

        $result = $this->compare(['Paris, France', 'London']);

        $this->assertCount(2, $result['cities']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'geocoding-api') && $r['name'] === 'Paris' && ! isset($r['countryCode']));
    }

    public function test_an_invalid_city_out_of_two_prevents_comparison(): void
    {
        Log::spy();
        $this->fakeOpenMeteo();

        $result = $this->runTool(['cities' => ['London', 'Atlantisville']]);

        $this->assertSame(
            'I could not find these cities: "Atlantisville". Ask the user to check the spelling or name the country. '
            .'Only London could be looked up, so no comparison is possible.',
            $result,
        );
        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $message, array $context) => $message === 'Weather tool: city not found'
                && $context['tool'] === 'CompareCitiesWeather'
                && $context['city'] === 'Atlantisville'
        );
    }

    public function test_an_invalid_city_out_of_three_still_compares_the_others(): void
    {
        $this->fakeOpenMeteo();

        $result = $this->compare(['London', 'Atlantisville', 'Ahmedabad']);

        $this->assertCount(2, $result['cities']);
        $this->assertSame(['Atlantisville'], $result['not_found']);
        $this->assertSame('Ahmedabad', $result['comparison']['warmest']['city']);
    }

    public function test_a_timeout_for_one_city_is_reported_as_unavailable(): void
    {
        Log::spy();
        $this->fakeOpenMeteo(current: [
            'paris' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'),
        ]);

        $result = $this->compare(['London', 'Paris', 'Rome']);

        $this->assertCount(2, $result['cities']);
        $this->assertSame(['Paris'], $result['unavailable']);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['city'] === 'Paris' && str_contains($context['error'], 'timed out')
        );
    }

    public function test_timeouts_for_every_city_return_a_message(): void
    {
        Log::spy();
        Http::fake([
            'https://geocoding-api.open-meteo.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        ]);

        $result = $this->runTool(['cities' => ['London', 'Paris']]);

        $this->assertSame('Weather data is unavailable right now for: "London", "Paris". No comparison is possible.', $result);
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_a_server_error_for_one_city_is_logged_with_its_status(): void
    {
        Log::spy();
        $this->fakeOpenMeteo(current: ['ahmedabad' => Http::response('Bad gateway', 502)]);

        $result = $this->compare(['London', 'Ahmedabad', 'Rome']);

        $this->assertSame(['Ahmedabad'], $result['unavailable']);
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => $message === 'Weather tool: Open-Meteo request failed'
                && $context['city'] === 'Ahmedabad'
                && $context['status'] === 502
        );
    }

    public function test_equal_temperatures_are_described_as_the_same(): void
    {
        $this->fakeOpenMeteo(current: [
            'paris' => Http::response(OpenMeteoFixtures::current('paris', ['temperature_2m' => 12.4])),
        ]);

        $result = $this->compare(['London', 'Paris']);

        $this->assertEquals(0, $result['comparison']['temperature_difference']['value']); // JSON encodes 0.0 as 0
        $this->assertStringStartsWith('Both cities have the same temperature (12.4°C).', $result['comparison']['summary']);
    }

    public function test_a_missing_temperature_is_excluded_from_the_ranking(): void
    {
        $this->fakeOpenMeteo(current: [
            'rome' => Http::response(OpenMeteoFixtures::current('rome', ['temperature_2m' => null])),
        ]);

        $result = $this->compare(['London', 'Rome', 'Ahmedabad']);

        $this->assertCount(3, $result['cities']);
        $this->assertNull($result['cities'][1]['current_weather']['temperature']);
        $this->assertSame('Ahmedabad', $result['comparison']['warmest']['city']);
        $this->assertSame('London', $result['comparison']['coldest']['city']);
    }

    public function test_two_names_for_the_same_place_are_merged(): void
    {
        $this->fakeOpenMeteo();

        $result = $this->compare(['London', 'Paris', 'London, GB']);

        $this->assertCount(2, $result['cities']);
        $this->assertSame(
            ['"London" and "London, GB" refer to the same place (London, United Kingdom), so it is only included once.'],
            $result['notes'],
        );
        Http::assertSentCount(5); // 3 geocoding + 2 current; the duplicate is not fetched
    }

    public function test_only_one_distinct_place_returns_a_message(): void
    {
        $this->fakeOpenMeteo();

        $result = $this->runTool(['cities' => ['London', 'London, GB']]);

        $this->assertStringContainsString('refer to the same place', $result);
        $this->assertStringEndsWith('Only London could be looked up, so no comparison is possible.', $result);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('invalidArguments')]
    public function test_invalid_arguments_raise_a_validation_error_for_the_model(array $arguments, string $expected): void
    {
        Http::fake();

        try {
            $this->runTool($arguments);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($expected, implode(' ', $e->validator->errors()->all()));
        }

        Http::assertNothingSent();
    }

    public static function invalidArguments(): array
    {
        return [
            'missing cities' => [[], 'cities field is required'],
            'one city' => [['cities' => ['London']], 'at least 2 items'],
            'six cities' => [['cities' => ['London', 'Paris', 'Rome', 'Ahmedabad', 'Mumbai', 'Delhi']], 'not have more than 5 items'],
            'comma string instead of list' => [['cities' => 'London, Paris'], 'must be an array'],
            'empty item' => [['cities' => ['London', '  ']], 'is required'],
            'non-string item' => [['cities' => ['London', ['name' => 'Paris']]], 'must be a string'],
            'duplicate (case-insensitive)' => [['cities' => ['London', 'london']], 'Each city may only be listed once.'],
            'object instead of list' => [['cities' => ['a' => 'London', 'b' => 'Paris']], 'must be a list'],
        ];
    }

    public function test_schema_matches_what_ollama_receives(): void
    {
        // Mirrors Laravel\Ai\Gateway\Ollama\Concerns\MapsTools::mapTool().
        $schema = (new ObjectSchema(app(CompareCitiesWeather::class)->schema(new JsonSchemaTypeFactory)))->toSchema();

        $this->assertSame(['cities'], $schema['required']);
        $this->assertSame('array', $schema['properties']['cities']['type']);
        $this->assertSame('string', $schema['properties']['cities']['items']['type']);
        $this->assertSame(2, $schema['properties']['cities']['minItems']);
        $this->assertSame(5, $schema['properties']['cities']['maxItems']);
    }

    /**
     * Fake Open-Meteo, answering per city. Overrides are keyed by lowercase city
     * and may be a response or a closure (e.g. one that throws).
     *
     * @param  array<string, mixed>  $current
     */
    private function fakeOpenMeteo(array $current = []): void
    {
        $resolve = fn (mixed $override) => $override instanceof Closure ? $override() : $override;

        Http::fake([
            'https://geocoding-api.open-meteo.com/*' => fn (HttpRequest $r) => Http::response(OpenMeteoFixtures::geocoding($r['name'])),
            'https://api.open-meteo.com/*' => function (HttpRequest $r) use ($current, $resolve) {
                $city = OpenMeteoFixtures::cityAt($r['latitude']);

                return isset($current[$city]) ? $resolve($current[$city]) : Http::response(OpenMeteoFixtures::current($city));
            },
        ]);
    }

    /**
     * @param  list<string>  $cities
     * @return array<string, mixed>
     */
    private function compare(array $cities): array
    {
        $raw = $this->runTool(['cities' => $cities]);
        $decoded = json_decode($raw, true);

        $this->assertIsArray($decoded, "Expected JSON, got: {$raw}");

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function runTool(array $arguments): string
    {
        return (string) app(CompareCitiesWeather::class)->handle(new Request($arguments));
    }
}
