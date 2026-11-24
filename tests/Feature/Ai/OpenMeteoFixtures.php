<?php

namespace Tests\Feature\Ai;

/**
 * Trimmed copies of real Open-Meteo geocoding and forecast responses.
 */
final class OpenMeteoFixtures
{
    /**
     * Real geocoding data plus fixed "current" readings per city.
     */
    private const CITIES = [
        'london' => [
            'geo' => ['id' => 2643743, 'name' => 'London', 'latitude' => 51.50853, 'longitude' => -0.12574, 'country_code' => 'GB', 'country' => 'United Kingdom', 'admin1' => 'England', 'timezone' => 'Europe/London'],
            'now' => ['temperature_2m' => 12.4, 'relative_humidity_2m' => 81, 'apparent_temperature' => 10.1, 'wind_speed_10m' => 14.2, 'weather_code' => 2],
        ],
        'ahmedabad' => [
            'geo' => ['id' => 1279233, 'name' => 'Ahmedabad', 'latitude' => 23.02579, 'longitude' => 72.58727, 'country_code' => 'IN', 'country' => 'India', 'admin1' => 'Gujarat', 'timezone' => 'Asia/Kolkata'],
            'now' => ['temperature_2m' => 30.8, 'relative_humidity_2m' => 22, 'apparent_temperature' => 29.2, 'wind_speed_10m' => 5.3, 'weather_code' => 0],
        ],
        'paris' => [
            'geo' => ['id' => 2988507, 'name' => 'Paris', 'latitude' => 48.85341, 'longitude' => 2.3488, 'country_code' => 'FR', 'country' => 'France', 'admin1' => 'Île-de-France', 'timezone' => 'Europe/Paris'],
            'now' => ['temperature_2m' => 9.6, 'relative_humidity_2m' => 87, 'apparent_temperature' => 7.0, 'wind_speed_10m' => 11.0, 'weather_code' => 3],
        ],
        'rome' => [
            'geo' => ['id' => 3169070, 'name' => 'Rome', 'latitude' => 41.89193, 'longitude' => 12.51133, 'country_code' => 'IT', 'country' => 'Italy', 'admin1' => 'Lazio', 'timezone' => 'Europe/Rome'],
            'now' => ['temperature_2m' => 16.2, 'relative_humidity_2m' => 70, 'apparent_temperature' => 15.4, 'wind_speed_10m' => 7.6, 'weather_code' => 1],
        ],
    ];

    /**
     * Geocoding response for a known city, or Open-Meteo's empty response for an unknown one.
     */
    public static function geocoding(string $name): array
    {
        $city = self::CITIES[strtolower(trim($name))] ?? null;

        return $city
            ? ['results' => [$city['geo'] + ['elevation' => 25.0, 'feature_code' => 'PPLC']], 'generationtime_ms' => 0.7]
            : ['generationtime_ms' => 0.4];
    }

    /**
     * Current-conditions response for a known city.
     *
     * @param  array<string, mixed>  $overrides  Values merged into "current".
     */
    public static function current(string $name, array $overrides = []): array
    {
        $city = self::CITIES[strtolower($name)];
        $payload = self::londonForecast();

        $payload['latitude'] = $city['geo']['latitude'];
        $payload['longitude'] = $city['geo']['longitude'];
        $payload['timezone'] = $city['geo']['timezone'];
        $payload['current'] = array_merge($payload['current'], $city['now'], $overrides);

        return $payload;
    }

    /**
     * Which fixture city a forecast request's latitude belongs to.
     */
    public static function cityAt(mixed $latitude): ?string
    {
        foreach (self::CITIES as $key => $city) {
            if (abs($city['geo']['latitude'] - (float) $latitude) < 0.001) {
                return $key;
            }
        }

        return null;
    }

    public static function londonGeocoding(): array
    {
        return [
            'results' => [[
                'id' => 2643743,
                'name' => 'London',
                'latitude' => 51.50853,
                'longitude' => -0.12574,
                'elevation' => 25.0,
                'feature_code' => 'PPLC',
                'country_code' => 'GB',
                'timezone' => 'Europe/London',
                'population' => 7556900,
                'country' => 'United Kingdom',
                'admin1' => 'England',
            ]],
            'generationtime_ms' => 0.7,
        ];
    }

    /**
     * Daily forecast in the real Open-Meteo shape (parallel arrays keyed by "time").
     */
    public static function londonDaily(int $days = 3): array
    {
        $dates = ['2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27', '2026-11-28', '2026-11-29', '2026-11-30'];
        $take = fn (array $values) => array_slice($values, 0, $days);

        return [
            'latitude' => 51.5,
            'longitude' => -0.120000124,
            'generationtime_ms' => 0.2,
            'utc_offset_seconds' => 0,
            'timezone' => 'Europe/London',
            'timezone_abbreviation' => 'GMT',
            'elevation' => 23.0,
            'daily_units' => [
                'time' => 'iso8601',
                'weather_code' => 'wmo code',
                'temperature_2m_max' => '°C',
                'temperature_2m_min' => '°C',
                'apparent_temperature_max' => '°C',
                'apparent_temperature_min' => '°C',
                'precipitation_sum' => 'mm',
                'precipitation_probability_max' => '%',
                'wind_speed_10m_max' => 'km/h',
                'sunrise' => 'iso8601',
                'sunset' => 'iso8601',
            ],
            'daily' => [
                'time' => $take($dates),
                'weather_code' => $take([2, 61, 3, 0, 80, 95, 1]),
                'temperature_2m_max' => $take([13.1, 11.4, 10.2, 9.8, 12.0, 14.3, 10.0]),
                'temperature_2m_min' => $take([7.2, 6.0, 4.9, 3.1, 5.5, 8.0, 4.0]),
                'apparent_temperature_max' => $take([11.0, 8.9, 7.7, 7.0, 9.6, 12.2, 8.1]),
                'apparent_temperature_min' => $take([4.8, 3.3, 2.0, 0.4, 2.9, 5.7, 1.5]),
                'precipitation_sum' => $take([0.0, 6.4, 0.2, 0.0, 3.1, 12.5, 0.0]),
                'precipitation_probability_max' => $take([10, 85, 20, 5, 60, 90, 15]),
                'wind_speed_10m_max' => $take([18.4, 27.0, 15.2, 9.9, 21.3, 33.8, 12.6]),
                'sunrise' => $take(array_map(fn ($d) => "{$d}T07:30", $dates)),
                'sunset' => $take(array_map(fn ($d) => "{$d}T16:00", $dates)),
            ],
        ];
    }

    public static function londonForecast(): array
    {
        return [
            'latitude' => 51.5,
            'longitude' => -0.120000124,
            'generationtime_ms' => 0.05,
            'utc_offset_seconds' => 0,
            'timezone' => 'Europe/London',
            'timezone_abbreviation' => 'GMT',
            'elevation' => 23.0,
            'current_units' => [
                'time' => 'iso8601',
                'interval' => 'seconds',
                'temperature_2m' => '°C',
                'relative_humidity_2m' => '%',
                'apparent_temperature' => '°C',
                'is_day' => '',
                'precipitation' => 'mm',
                'weather_code' => 'wmo code',
                'wind_speed_10m' => 'km/h',
            ],
            'current' => [
                'time' => '2026-11-24T14:00',
                'interval' => 900,
                'temperature_2m' => 12.4,
                'relative_humidity_2m' => 81,
                'apparent_temperature' => 10.1,
                'is_day' => 1,
                'precipitation' => 0.0,
                'weather_code' => 2,
                'wind_speed_10m' => 14.2,
            ],
        ];
    }
}
