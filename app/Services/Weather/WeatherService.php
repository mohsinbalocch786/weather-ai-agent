<?php

namespace App\Services\Weather;

use App\Services\OpenMeteo\OpenMeteoClient;
use App\Services\OpenMeteo\WeatherCode;
use App\Services\Weather\Exceptions\CityNotFoundException;
use App\Services\Weather\Exceptions\WeatherDataUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Weather business logic: turns raw Open-Meteo payloads into normalized results.
 *
 * Throws CityNotFoundException / WeatherDataUnavailableException for domain
 * problems and lets ConnectionException / RequestException propagate, so the
 * caller (an AI tool today, a controller later) decides how to present them.
 */
class WeatherService
{
    /**
     * Current-condition variables requested from Open-Meteo.
     */
    private const CURRENT_FIELDS = [
        'temperature_2m',
        'relative_humidity_2m',
        'apparent_temperature',
        'is_day',
        'precipitation',
        'weather_code',
        'wind_speed_10m',
    ];

    /**
     * Daily variable => key in the normalized forecast.
     */
    private const DAILY_FIELDS = [
        'weather_code' => 'condition',
        'temperature_2m_max' => 'temperature_max',
        'temperature_2m_min' => 'temperature_min',
        'apparent_temperature_max' => 'feels_like_max',
        'apparent_temperature_min' => 'feels_like_min',
        'precipitation_sum' => 'precipitation_sum',
        'precipitation_probability_max' => 'precipitation_probability_max',
        'wind_speed_10m_max' => 'wind_speed_max',
        'sunrise' => 'sunrise',
        'sunset' => 'sunset',
    ];

    public function __construct(
        private readonly OpenMeteoClient $client,
        private readonly WeatherComparator $comparator,
    ) {}

    /**
     * Resolve a city to its best-matching geocoding result.
     *
     * @return array<string, mixed>
     *
     * @throws CityNotFoundException
     * @throws WeatherDataUnavailableException
     * @throws ConnectionException
     * @throws RequestException
     */
    public function locate(string $city, ?string $countryCode = null): array
    {
        $location = $this->client->geocode($city, $countryCode);

        if ($location === null) {
            throw new CityNotFoundException($city);
        }

        if (! is_numeric($location['latitude'] ?? null) || ! is_numeric($location['longitude'] ?? null)) {
            throw new WeatherDataUnavailableException('geocoding result missing coordinates', ['result' => $location]);
        }

        return $location;
    }

    /**
     * @return array{location: array<string, mixed>, current_weather: array<string, mixed>, source: string}
     *
     * @throws CityNotFoundException
     * @throws WeatherDataUnavailableException
     * @throws ConnectionException
     * @throws RequestException
     */
    public function current(string $city, ?string $countryCode = null): array
    {
        return $this->currentAt($this->locate($city, $countryCode), $city);
    }

    /**
     * Current conditions for an already-resolved location.
     *
     * @param  array<string, mixed>  $location
     * @return array{location: array<string, mixed>, current_weather: array<string, mixed>, source: string}
     *
     * @throws WeatherDataUnavailableException
     * @throws ConnectionException
     * @throws RequestException
     */
    public function currentAt(array $location, string $fallbackCity): array
    {
        $payload = $this->client->current($location['latitude'], $location['longitude'], self::CURRENT_FIELDS);

        $current = $payload['current'] ?? null;

        if (! is_array($current) || ! Arr::has($current, ['time', 'temperature_2m', 'relative_humidity_2m'])) {
            throw new WeatherDataUnavailableException('forecast payload missing current data', ['keys' => array_keys($payload)]);
        }

        $units = is_array($payload['current_units'] ?? null) ? $payload['current_units'] : [];

        return [
            'location' => $this->locationPayload($location, $fallbackCity, $payload['timezone'] ?? null),
            'current_weather' => [
                'observed_at' => $current['time'],
                'condition' => WeatherCode::describe($current['weather_code'] ?? null),
                'temperature' => $this->measurement($current, $units, 'temperature_2m', '°C'),
                'feels_like' => $this->measurement($current, $units, 'apparent_temperature', '°C'),
                'humidity' => $this->measurement($current, $units, 'relative_humidity_2m', '%'),
                'wind_speed' => $this->measurement($current, $units, 'wind_speed_10m', 'km/h'),
                'precipitation' => $this->measurement($current, $units, 'precipitation', 'mm'),
                'is_day' => isset($current['is_day']) ? (bool) $current['is_day'] : null,
            ],
            'source' => 'Open-Meteo',
        ];
    }

    /**
     * @return array{location: array<string, mixed>, units: array<string, string>, forecast: list<array<string, mixed>>, source: string}
     *
     * @throws CityNotFoundException
     * @throws WeatherDataUnavailableException
     * @throws ConnectionException
     * @throws RequestException
     */
    public function forecast(string $city, ?string $countryCode, int $days): array
    {
        $location = $this->locate($city, $countryCode);

        $payload = $this->client->daily(
            $location['latitude'], $location['longitude'], array_keys(self::DAILY_FIELDS), $days
        );

        $daily = $payload['daily'] ?? null;
        $dates = is_array($daily) ? ($daily['time'] ?? null) : null;

        if (! is_array($dates) || $dates === []) {
            throw new WeatherDataUnavailableException(
                'forecast payload missing daily data',
                ['keys' => array_keys($payload)],
                'Weather forecast information is currently unavailable.',
            );
        }

        return [
            'location' => $this->locationPayload($location, $city, $payload['timezone'] ?? null),
            'units' => $this->dailyUnits($payload['daily_units'] ?? null),
            'forecast' => $this->dailyEntries($daily, array_values($dates)),
            'source' => 'Open-Meteo',
        ];
    }

    /**
     * Fetch current weather for several cities and compare them.
     *
     * Per-city failures never abort the comparison; they are returned in
     * "failures" so the caller can log and report them.
     *
     * @param  list<array{city: string, country_code: ?string}>  $queries
     * @return array{
     *     cities: list<array<string, mixed>>,
     *     comparison: array<string, mixed>|null,
     *     failures: list<array{query: array{city: string, country_code: ?string}, error: Throwable}>,
     *     notes: list<string>,
     * }
     */
    public function compare(array $queries): array
    {
        $cities = [];
        $failures = [];
        $notes = [];
        $seen = []; // location key => original query label

        foreach ($queries as $query) {
            $label = $this->queryLabel($query);

            try {
                $location = $this->locate($query['city'], $query['country_code']);

                $key = $this->locationKey($location);

                if (isset($seen[$key])) {
                    $notes[] = sprintf(
                        '"%s" and "%s" refer to the same place (%s), so it is only included once.',
                        $seen[$key], $label, $this->placeName($location),
                    );

                    continue;
                }

                $seen[$key] = $label;
                $cities[] = $this->currentAt($location, $query['city']);
            } catch (CityNotFoundException|WeatherDataUnavailableException|ConnectionException|RequestException $e) {
                $failures[] = ['query' => $query, 'error' => $e];
            }
        }

        return [
            'cities' => $cities,
            'comparison' => count($cities) >= 2 ? $this->comparator->compare($cities) : null,
            'failures' => $failures,
            'notes' => $notes,
        ];
    }

    /**
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    private function locationPayload(array $location, string $fallbackCity, ?string $timezone): array
    {
        return [
            'city' => $location['name'] ?? $fallbackCity,
            'region' => $location['admin1'] ?? null,
            'country' => $location['country'] ?? null,
            'country_code' => $location['country_code'] ?? null,
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'timezone' => $timezone ?? $location['timezone'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $units
     * @return array{value: mixed, unit: string}|null
     */
    private function measurement(array $current, array $units, string $key, string $defaultUnit): ?array
    {
        if (! isset($current[$key])) {
            return null;
        }

        return ['value' => $current[$key], 'unit' => $units[$key] ?? $defaultUnit];
    }

    /**
     * Turn Open-Meteo's parallel daily arrays into one entry per day.
     * Missing values, nulls and ragged arrays become null rather than failing.
     *
     * @param  array<string, mixed>  $daily
     * @param  list<mixed>  $dates
     * @return list<array<string, mixed>>
     */
    private function dailyEntries(array $daily, array $dates): array
    {
        $entries = [];

        foreach ($dates as $index => $date) {
            $entry = ['date' => $date, 'label' => $this->dayLabel($date, $index)];

            foreach (self::DAILY_FIELDS as $field => $key) {
                $value = is_array($daily[$field] ?? null) ? ($daily[$field][$index] ?? null) : null;

                $entry[$key] = $field === 'weather_code' ? WeatherCode::describe($value) : $value;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * A human label so a small model need not do date arithmetic.
     * The first date is "today" in the location's own timezone (timezone=auto).
     */
    private function dayLabel(mixed $date, int $index): ?string
    {
        return match ($index) {
            0 => 'Today',
            1 => 'Tomorrow',
            default => rescue(fn () => Carbon::parse((string) $date)->format('l'), null, false),
        };
    }

    /**
     * @return array<string, string>
     */
    private function dailyUnits(mixed $units): array
    {
        $units = is_array($units) ? $units : [];

        return [
            'temperature' => $units['temperature_2m_max'] ?? '°C',
            'precipitation_sum' => $units['precipitation_sum'] ?? 'mm',
            'precipitation_probability_max' => $units['precipitation_probability_max'] ?? '%',
            'wind_speed' => $units['wind_speed_10m_max'] ?? 'km/h',
        ];
    }

    /**
     * Identify a geocoded place: Open-Meteo's id, falling back to rounded coordinates.
     *
     * @param  array<string, mixed>  $location
     */
    private function locationKey(array $location): string
    {
        if (isset($location['id'])) {
            return 'id:'.$location['id'];
        }

        return sprintf('ll:%.3f,%.3f', $location['latitude'], $location['longitude']);
    }

    /**
     * @param  array<string, mixed>  $location
     */
    private function placeName(array $location): string
    {
        return implode(', ', array_filter([$location['name'] ?? null, $location['country'] ?? null]));
    }

    /**
     * @param  array{city: string, country_code: ?string}  $query
     */
    private function queryLabel(array $query): string
    {
        return $query['country_code'] ? "{$query['city']}, {$query['country_code']}" : $query['city'];
    }
}
