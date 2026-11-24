<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\InteractsWithOpenMeteo;
use App\Services\Weather\Exceptions\CityNotFoundException;
use App\Services\Weather\WeatherService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CompareCitiesWeather implements Tool
{
    use InteractsWithOpenMeteo;

    public const MIN_CITIES = 2;

    public const MAX_CITIES = 5;

    public function __construct(private readonly WeatherService $weather) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Compare the weather RIGHT NOW in 2 to '.self::MAX_CITIES.' cities in a single call. '
            .'Returns each city\'s conditions plus a ready-made comparison (warmest, coldest, temperature difference, most humid, windiest) and a summary sentence. '
            .'Use this whenever the user compares cities: "compare", "X vs Y", "which is warmer", "is it hotter in X or Y".';
    }

    /**
     * Execute the tool.
     *
     * Validation errors are thrown on purpose: the SDK returns them to the
     * model as text so it can retry the call with corrected arguments.
     */
    public function handle(Request $request): Stringable|string
    {
        $input = $request->validate([
            'cities' => ['required', 'array', 'list', 'min:'.self::MIN_CITIES, 'max:'.self::MAX_CITIES],
            'cities.*' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
        ], [
            'cities.*.distinct' => 'Each city may only be listed once.',
        ]);

        $queries = array_map($this->parseCity(...), $input['cities']);
        $context = ['tool' => class_basename($this), 'cities' => array_column($queries, 'city')];
        $startedAt = microtime(true);

        $result = $this->weather->compare($queries);

        $notFound = [];
        $unavailable = [];

        foreach ($result['failures'] as ['query' => $query, 'error' => $error]) {
            $this->reportFailure($error, $this->logContext($query['city'], $query['country_code']));

            if ($error instanceof CityNotFoundException) {
                $notFound[] = $query['city'];
            } else {
                $unavailable[] = $query['city'];
            }
        }

        if ($result['comparison'] === null) {
            return $this->notComparableMessage($result['cities'], $notFound, $unavailable, $result['notes']);
        }

        Log::debug('Weather tool: success', $context + [
            'compared' => array_map(fn (array $c) => $c['location']['city'] ?? null, $result['cities']),
            'not_found' => $notFound,
            'unavailable' => $unavailable,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $this->encode([
            'cities' => $result['cities'],
            'comparison' => $result['comparison'],
            'not_found' => $notFound,
            'unavailable' => $unavailable,
            'notes' => $result['notes'],
            'source' => 'Open-Meteo',
        ]);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'cities' => $schema->array()
                ->items(
                    $schema->string()
                        ->description('A city name, optionally followed by a comma and a two-letter country code, for example "London" or "Paris, FR".')
                        ->min(1)
                        ->max(100)
                )
                ->description('All the cities to compare, as a list of names, for example ["London", "Ahmedabad"].')
                ->min(self::MIN_CITIES)
                ->max(self::MAX_CITIES)
                ->required(),
        ];
    }

    /**
     * Split "Paris, FR" into city and country code.
     *
     * A trailing country *name* ("Paris, France") is dropped because Open-Meteo
     * geocoding only matches on the place name.
     *
     * @return array{city: string, country_code: ?string}
     */
    private function parseCity(string $value): array
    {
        $value = trim($value);

        if (preg_match('/^(.+?)\s*,\s*([A-Za-z]{2})$/u', $value, $m)) {
            return ['city' => trim($m[1]), 'country_code' => strtoupper($m[2])];
        }

        if (str_contains($value, ',')) {
            return ['city' => trim(strstr($value, ',', true)), 'country_code' => null];
        }

        return ['city' => $value, 'country_code' => null];
    }

    /**
     * Explain why fewer than two distinct cities could be compared.
     *
     * @param  list<array<string, mixed>>  $cities
     * @param  list<string>  $notFound
     * @param  list<string>  $unavailable
     * @param  list<string>  $notes
     */
    private function notComparableMessage(array $cities, array $notFound, array $unavailable, array $notes): string
    {
        $quote = fn (array $names): string => implode(', ', array_map(fn ($n) => "\"{$n}\"", $names));

        $parts = [];

        if ($notFound !== []) {
            $parts[] = 'I could not find these cities: '.$quote($notFound).'. Ask the user to check the spelling or name the country.';
        }

        if ($unavailable !== []) {
            $parts[] = 'Weather data is unavailable right now for: '.$quote($unavailable).'.';
        }

        array_push($parts, ...$notes);

        $parts[] = match (count($cities)) {
            0 => 'No comparison is possible.',
            default => sprintf(
                'Only %s could be looked up, so no comparison is possible.',
                $cities[0]['location']['city'] ?? 'one city',
            ),
        };

        return implode(' ', $parts);
    }
}
