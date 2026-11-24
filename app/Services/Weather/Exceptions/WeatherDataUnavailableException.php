<?php

namespace App\Services\Weather\Exceptions;

use RuntimeException;

/**
 * Open-Meteo answered successfully but the payload is missing data we need.
 */
class WeatherDataUnavailableException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details  Extra log context (never shown to the user).
     */
    public function __construct(
        string $message,
        public readonly array $details = [],
        public readonly string $userMessage = 'Weather information is currently unavailable.',
    ) {
        parent::__construct($message);
    }
}
