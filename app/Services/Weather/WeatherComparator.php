<?php

namespace App\Services\Weather;

/**
 * Deterministic comparison of normalized current-weather results.
 *
 * Doing the maths here (not in the model) keeps comparisons correct even
 * with a small local model: it only has to relay the summary.
 */
class WeatherComparator
{
    /**
     * Temperature differences below this are reported as "the same".
     */
    private const EPSILON = 0.05;

    /**
     * @param  list<array{location: array<string, mixed>, current_weather: array<string, mixed>}>  $cities
     * @return array<string, mixed>
     */
    public function compare(array $cities): array
    {
        $names = $this->names($cities);

        $temperature = $this->values($cities, $names, 'temperature');
        $humidity = $this->values($cities, $names, 'humidity');
        $wind = $this->values($cities, $names, 'wind_speed');

        $warmest = $this->extreme($temperature, max: true);
        $coldest = $this->extreme($temperature, max: false);

        return [
            'warmest' => $warmest,
            'coldest' => $coldest,
            'temperature_difference' => $warmest && $coldest
                ? ['value' => round($warmest['value'] - $coldest['value'], 1), 'unit' => $warmest['unit']]
                : null,
            'most_humid' => $this->extreme($humidity, max: true),
            'windiest' => $this->extreme($wind, max: true),
            'summary' => implode(' ', array_filter([
                $this->temperatureSentence($temperature, count($cities)),
                $this->rankingSentence($humidity, count($cities), 'more humid', 'the most humid', 'humidity'),
                $this->rankingSentence($wind, count($cities), 'windier', 'the windiest', 'wind speed'),
                $this->conditionsSentence($cities, $names),
            ])),
        ];
    }

    /**
     * Display names, disambiguated when two places share a name (e.g. Paris, FR / Paris, US).
     *
     * @param  list<array<string, mixed>>  $cities
     * @return list<string>
     */
    private function names(array $cities): array
    {
        $plain = array_map(fn (array $c): string => (string) ($c['location']['city'] ?? 'Unknown'), $cities);
        $counts = array_count_values($plain);

        return array_map(function (array $c, string $name) use ($counts): string {
            if ($counts[$name] === 1) {
                return $name;
            }

            $qualifier = $c['location']['region'] ?? $c['location']['country'] ?? $c['location']['country_code'] ?? null;

            return $qualifier ? "{$name} ({$qualifier})" : $name;
        }, $cities, $plain);
    }

    /**
     * Non-null numeric values of one metric, keyed by display name.
     *
     * @param  list<array<string, mixed>>  $cities
     * @param  list<string>  $names
     * @return array{values: array<string, float>, unit: ?string}
     */
    private function values(array $cities, array $names, string $metric): array
    {
        $values = [];
        $unit = null;

        foreach ($cities as $i => $city) {
            $measurement = $city['current_weather'][$metric] ?? null;

            if (is_array($measurement) && is_numeric($measurement['value'] ?? null)) {
                $values[$names[$i]] = (float) $measurement['value'];
                $unit ??= $measurement['unit'] ?? null;
            }
        }

        return ['values' => $values, 'unit' => $unit];
    }

    /**
     * Highest / lowest value, naming every city that shares it. Needs at least two values.
     *
     * @param  array{values: array<string, float>, unit: ?string}  $metric
     * @return array{city: string, value: float, unit: ?string}|null
     */
    private function extreme(array $metric, bool $max): ?array
    {
        if (count($metric['values']) < 2) {
            return null;
        }

        $target = $max ? max($metric['values']) : min($metric['values']);
        $leaders = array_keys(array_filter($metric['values'], fn (float $v): bool => abs($v - $target) < self::EPSILON));

        return ['city' => $this->join($leaders), 'value' => $target, 'unit' => $metric['unit']];
    }

    /**
     * @param  array{values: array<string, float>, unit: ?string}  $metric
     */
    private function temperatureSentence(array $metric, int $cityCount): ?string
    {
        $values = $metric['values'];

        if (count($values) < 2) {
            return null;
        }

        $unit = $metric['unit'] ?? '°C';
        $high = max($values);
        $low = min($values);

        if ($high - $low < self::EPSILON) {
            return sprintf('%s have the same temperature (%s%s).', $this->both(array_keys($values), $cityCount), $this->num($high), $unit);
        }

        $warmest = $this->extreme($metric, max: true);
        $coldest = $this->extreme($metric, max: false);
        $difference = $this->num($high - $low);

        if (count($values) === 2) {
            return sprintf(
                '%s (%s%s) is %s%s warmer than %s (%s%s).',
                $warmest['city'], $this->num($high), $unit, $difference, $unit, $coldest['city'], $this->num($low), $unit,
            );
        }

        return sprintf(
            '%s %s the warmest at %s%s and %s %s the coldest at %s%s, a difference of %s%s.',
            $warmest['city'], $this->verb($warmest['city']), $this->num($high), $unit,
            $coldest['city'], $this->verb($coldest['city']), $this->num($low), $unit,
            $difference, $unit,
        );
    }

    /**
     * @param  array{values: array<string, float>, unit: ?string}  $metric
     */
    private function rankingSentence(array $metric, int $cityCount, string $comparative, string $superlative, string $noun): ?string
    {
        $values = $metric['values'];

        if (count($values) < 2) {
            return null;
        }

        $unit = $metric['unit'] ?? '';
        $format = fn (float $v): string => $this->num($v).($unit === '%' ? '%' : '');
        $suffix = $unit !== '' && $unit !== '%' ? " {$unit}" : '';

        if (max($values) - min($values) < self::EPSILON) {
            return sprintf('%s have the same %s (%s%s).', $this->both(array_keys($values), $cityCount), $noun, $format(max($values)), $suffix);
        }

        $top = $this->extreme($metric, max: true);

        if (count($values) === 2) {
            return sprintf(
                '%s is %s (%s vs %s%s).',
                $top['city'], $comparative, $format(max($values)), $format(min($values)), $suffix,
            );
        }

        return sprintf('%s %s %s (%s%s).', $top['city'], $this->verb($top['city']), $superlative, $format($top['value']), $suffix);
    }

    /**
     * @param  list<array<string, mixed>>  $cities
     * @param  list<string>  $names
     */
    private function conditionsSentence(array $cities, array $names): string
    {
        $parts = [];

        foreach ($cities as $i => $city) {
            $parts[] = $names[$i].': '.lcfirst((string) ($city['current_weather']['condition'] ?? 'unknown'));
        }

        return 'Conditions: '.implode(', ', $parts).'.';
    }

    /**
     * @param  list<string>  $names
     */
    private function both(array $names, int $cityCount): string
    {
        if (count($names) === 2 && $cityCount === 2) {
            return 'Both cities';
        }

        return count($names) === $cityCount ? 'All cities' : $this->join($names);
    }

    /**
     * @param  list<string>  $items
     */
    private function join(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        return implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }

    private function verb(string $subject): string
    {
        return str_contains($subject, ' and ') ? 'are' : 'is';
    }

    private function num(float $value): string
    {
        $rounded = round($value, 1);

        return $rounded == (int) $rounded ? (string) (int) $rounded : (string) $rounded;
    }
}
