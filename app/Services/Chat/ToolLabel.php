<?php

namespace App\Services\Chat;

/**
 * Short human descriptions of tool activity, shared by live and stored messages.
 */
final class ToolLabel
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function describe(string $tool, array $arguments): string
    {
        $city = is_string($arguments['city'] ?? null) ? trim($arguments['city']) : '';

        $cities = array_values(array_map('trim', array_filter(
            is_array($arguments['cities'] ?? null) ? $arguments['cities'] : [],
            'is_string',
        )));

        return match ($tool) {
            'GetCurrentWeather' => $city !== '' ? "Checking current weather in {$city}" : 'Checking current weather',
            'GetWeatherForecast' => $city !== '' ? "Fetching the forecast for {$city}" : 'Fetching the forecast',
            'CompareCitiesWeather' => $cities !== [] ? 'Comparing '.self::join($cities) : 'Comparing cities',
            default => 'Looking that up',
        };
    }

    /**
     * @param  list<string>  $items
     */
    private static function join(array $items): string
    {
        return count($items) > 1
            ? implode(', ', array_slice($items, 0, -1)).' and '.end($items)
            : $items[0];
    }
}
