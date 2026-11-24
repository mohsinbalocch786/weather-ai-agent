<?php

namespace App\Services\OpenMeteo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin wrapper around the Open-Meteo geocoding and forecast APIs.
 *
 * Every method throws ConnectionException (network / timeout) or
 * RequestException (non-2xx) so callers decide how to report failures.
 */
class OpenMeteoClient
{
    /**
     * Resolve a city name to its best-matching location, or null when nothing matches.
     *
     * @return array<string, mixed>|null
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function geocode(string $city, ?string $countryCode = null): ?array
    {
        $response = $this->http()->get(config('services.open_meteo.geocoding_url'), array_filter([
            'name' => $city,
            'count' => 1,
            'language' => 'en',
            'format' => 'json',
            'countryCode' => $countryCode,
        ]));

        // Open-Meteo omits the "results" key entirely when nothing matches.
        $location = $response->throw()->json('results.0');

        return is_array($location) ? $location : null;
    }

    /**
     * Fetch current conditions for the given coordinates.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function current(float|int|string $latitude, float|int|string $longitude, array $fields): array
    {
        return $this->forecast([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'current' => implode(',', $fields),
            'timezone' => 'auto',
        ]);
    }

    /**
     * Fetch a daily forecast for the given coordinates.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function daily(float|int|string $latitude, float|int|string $longitude, array $fields, int $days): array
    {
        return $this->forecast([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'daily' => implode(',', $fields),
            'forecast_days' => $days,
            'timezone' => 'auto',
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    private function forecast(array $query): array
    {
        $payload = $this->http()
            ->get(config('services.open_meteo.forecast_url'), $query)
            ->throw()
            ->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * Short connect timeout; retry once on connection failures and 5xx responses.
     */
    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout((int) config('services.open_meteo.timeout', 10))
            ->retry(
                2,
                200,
                fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()),
                throw: false,
            );
    }
}
