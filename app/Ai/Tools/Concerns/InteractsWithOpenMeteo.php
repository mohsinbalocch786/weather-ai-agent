<?php

namespace App\Ai\Tools\Concerns;

use App\Services\Weather\Exceptions\CityNotFoundException;
use App\Services\Weather\Exceptions\WeatherDataUnavailableException;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared schema, validation, error reporting and logging for weather tools.
 *
 * Only expected failures (unknown city, missing data, network, HTTP errors)
 * become model-friendly messages. Anything else propagates so the SDK
 * reports it and the bug stays visible.
 */
trait InteractsWithOpenMeteo
{
    /**
     * Schema properties shared by single-location weather tools.
     *
     * @return array<string, Type>
     */
    protected function locationSchema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()
                ->description('City name only, for example "Ahmedabad", "Mumbai" or "London".')
                ->min(1)
                ->max(100)
                ->required(),

            'country_code' => $schema->string()
                ->description('Optional two-letter ISO 3166-1 country code to disambiguate the city, for example "IN", "GB" or "US".')
                ->min(2)
                ->max(2),
        ];
    }

    /**
     * Validation rules shared by single-location weather tools.
     *
     * @return array<string, list<string>>
     */
    protected function locationRules(): array
    {
        return [
            'city' => ['required', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'size:2'],
        ];
    }

    /**
     * Base log context for a lookup.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function logContext(string $city, ?string $countryCode, array $extra = []): array
    {
        return ['tool' => class_basename($this), 'city' => $city, 'country_code' => $countryCode] + $extra;
    }

    /**
     * Run a lookup and turn its result or expected failure into the tool's reply.
     *
     * @param  array<string, mixed>  $context
     * @param  Closure(): array<string, mixed>  $callback
     */
    protected function respond(array $context, Closure $callback): string
    {
        $startedAt = microtime(true);

        try {
            $result = $callback();
        } catch (CityNotFoundException|WeatherDataUnavailableException|ConnectionException|RequestException $e) {
            return $this->reportFailure($e, $context);
        }

        $location = $result['location'] ?? [];

        Log::debug('Weather tool: success', $context + [
            'resolved' => trim(($location['city'] ?? '').', '.($location['country'] ?? ''), ', '),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $this->encode($result);
    }

    /**
     * Log an expected failure and return the message the model should see.
     *
     * @param  array<string, mixed>  $context
     */
    protected function reportFailure(Throwable $e, array $context): string
    {
        switch (true) {
            case $e instanceof CityNotFoundException:
                Log::info('Weather tool: city not found', $context);

                return "I could not find a city named \"{$e->city}\". Ask the user to check the spelling or name the country.";

            case $e instanceof WeatherDataUnavailableException:
                Log::error('Weather tool: '.$e->getMessage(), $context + $e->details);

                return $e->userMessage;

            case $e instanceof ConnectionException:
                Log::warning('Weather tool: Open-Meteo unreachable', $context + ['error' => $e->getMessage()]);

                return 'The weather service could not be reached right now. Please try again shortly.';

            case $e instanceof RequestException:
                Log::error('Weather tool: Open-Meteo request failed', $context + [
                    'status' => $e->response->status(),
                    'reason' => $e->response->json('reason'),
                    'url' => (string) $e->response->effectiveUri(),
                ]);

                return 'Unable to retrieve weather information right now. Please try again.';

            default:
                throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
