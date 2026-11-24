<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\InteractsWithOpenMeteo;
use App\Services\Weather\WeatherService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetCurrentWeather implements Tool
{
    use InteractsWithOpenMeteo;

    public function __construct(private readonly WeatherService $weather) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get the weather conditions RIGHT NOW in ONE city (temperature, humidity, wind, precipitation). '
            .'Use this for questions about the present moment, like "what is the weather now" or "is it raining currently". '
            .'Do not use it for tomorrow, later, or future days, or to compare several cities.';
    }

    /**
     * Execute the tool.
     *
     * Validation errors are thrown on purpose: the SDK returns them to the
     * model as text so it can retry the call with corrected arguments.
     */
    public function handle(Request $request): Stringable|string
    {
        $input = $request->validate($this->locationRules());

        $city = trim($input['city']);
        $countryCode = isset($input['country_code']) ? strtoupper($input['country_code']) : null;

        return $this->respond(
            $this->logContext($city, $countryCode),
            fn (): array => $this->weather->current($city, $countryCode),
        );
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->locationSchema($schema);
    }
}
