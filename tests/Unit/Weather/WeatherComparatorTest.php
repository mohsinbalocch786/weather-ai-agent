<?php

namespace Tests\Unit\Weather;

use App\Services\Weather\WeatherComparator;
use PHPUnit\Framework\TestCase;

class WeatherComparatorTest extends TestCase
{
    public function test_two_cities(): void
    {
        $result = $this->compare([
            $this->city('London', 12.4, 81, 14.2, 'Partly cloudy'),
            $this->city('Ahmedabad', 30.8, 22, 5.3, 'Clear sky'),
        ]);

        $this->assertSame('Ahmedabad', $result['warmest']['city']);
        $this->assertSame('London', $result['coldest']['city']);
        $this->assertSame(18.4, $result['temperature_difference']['value']);
        $this->assertSame(
            'Ahmedabad (30.8°C) is 18.4°C warmer than London (12.4°C). London is more humid (81% vs 22%). '
            .'London is windier (14.2 vs 5.3 km/h). Conditions: London: partly cloudy, Ahmedabad: clear sky.',
            $result['summary'],
        );
    }

    public function test_ties_name_every_leading_city(): void
    {
        $result = $this->compare([
            $this->city('Paris', 20.0, 60, 10.0),
            $this->city('Rome', 20.0, 50, 12.0),
            $this->city('London', 10.0, 70, 8.0),
        ]);

        $this->assertSame('Paris and Rome', $result['warmest']['city']);
        $this->assertStringStartsWith(
            'Paris and Rome are the warmest at 20°C and London is the coldest at 10°C, a difference of 10°C.',
            $result['summary'],
        );
    }

    public function test_all_equal_values(): void
    {
        $result = $this->compare([
            $this->city('A', 15.0, 50, 10.0),
            $this->city('B', 15.0, 50, 10.0),
            $this->city('C', 15.0, 50, 10.0),
        ]);

        $this->assertSame(0.0, $result['temperature_difference']['value']);
        $this->assertStringStartsWith(
            'All cities have the same temperature (15°C). All cities have the same humidity (50%). All cities have the same wind speed (10 km/h).',
            $result['summary'],
        );
    }

    public function test_metrics_missing_for_too_many_cities_are_null_and_left_out_of_the_summary(): void
    {
        $result = $this->compare([
            $this->city('A', null, null, 10.0),
            $this->city('B', 18.0, null, 12.0),
        ]);

        $this->assertNull($result['warmest']);
        $this->assertNull($result['coldest']);
        $this->assertNull($result['temperature_difference']);
        $this->assertNull($result['most_humid']);
        $this->assertSame('B is windier (12 vs 10 km/h). Conditions: A: clear sky, B: clear sky.', $result['summary']);
    }

    public function test_cities_with_the_same_name_are_disambiguated(): void
    {
        $result = $this->compare([
            $this->city('Paris', 9.6, 87, 11.0, region: 'Île-de-France'),
            $this->city('Paris', 22.0, 55, 15.0, region: 'Texas'),
        ]);

        $this->assertSame('Paris (Texas)', $result['warmest']['city']);
        $this->assertSame('Paris (Île-de-France)', $result['coldest']['city']);
    }

    private function compare(array $cities): array
    {
        return (new WeatherComparator)->compare($cities);
    }

    private function city(string $name, ?float $temperature, ?int $humidity, ?float $wind, string $condition = 'Clear sky', ?string $region = null): array
    {
        $measure = fn ($value, string $unit) => $value === null ? null : ['value' => $value, 'unit' => $unit];

        return [
            'location' => ['city' => $name, 'region' => $region, 'country' => null, 'country_code' => null],
            'current_weather' => [
                'condition' => $condition,
                'temperature' => $measure($temperature, '°C'),
                'humidity' => $measure($humidity, '%'),
                'wind_speed' => $measure($wind, 'km/h'),
            ],
        ];
    }
}
