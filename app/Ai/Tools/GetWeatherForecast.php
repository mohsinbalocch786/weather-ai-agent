<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\InteractsWithOpenMeteo;
use App\Services\Weather\WeatherService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetWeatherForecast implements Tool
{
    use InteractsWithOpenMeteo;

    public const DEFAULT_DAYS = 2;

    /**
     * Open-Meteo allows up to 16, but long forecasts make qwen2.5:3b answers
     * slow enough to hit the SDK's 60s Ollama timeout.
     */
    public const MAX_DAYS = 7;

    public function __construct(private readonly WeatherService $weather) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get the daily weather FORECAST for ONE city for up to '.self::MAX_DAYS.' days, starting today '
            .'(high/low temperature, chance of rain, rainfall, wind, sunrise and sunset). '
            .'Use this for future questions: tomorrow, later today, this weekend, this week, next few days, '
            .'"will it rain", or today\'s high and low.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $input = $request->validate($this->locationRules() + [
            'days' => ['nullable', 'integer', 'between:1,'.self::MAX_DAYS],
        ]);

        $city = trim($input['city']);
        $countryCode = isset($input['country_code']) ? strtoupper($input['country_code']) : null;
        $days = (int) ($input['days'] ?? self::DEFAULT_DAYS);

        return $this->respond(
            $this->logContext($city, $countryCode, ['days' => $days]),
            fn (): array => $this->weather->forecast($city, $countryCode, $days),
        );
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->locationSchema($schema) + [
            'days' => $schema->integer()
                ->description('Number of days to forecast, counting today as day 1. Use 2 for tomorrow, 7 for this week. Maximum '.self::MAX_DAYS.'. Defaults to '.self::DEFAULT_DAYS.'.')
                ->min(1)
                ->max(self::MAX_DAYS),
        ];
    }
}
