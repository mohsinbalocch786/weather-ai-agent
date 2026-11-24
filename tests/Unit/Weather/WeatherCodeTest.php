<?php

namespace Tests\Unit\Weather;

use App\Services\OpenMeteo\WeatherCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The table is copied from Open-Meteo's documentation on purpose: a typo in the
 * app's table must fail here instead of silently mislabelling the weather.
 *
 * @see https://open-meteo.com/en/docs (WMO Weather interpretation codes)
 */
class WeatherCodeTest extends TestCase
{
    #[DataProvider('documentedCodes')]
    public function test_every_documented_code_has_the_expected_description(int $code, string $expected): void
    {
        $this->assertSame($expected, WeatherCode::describe($code));
    }

    public static function documentedCodes(): array
    {
        $table = [
            0 => 'Clear sky',
            1 => 'Mainly clear',
            2 => 'Partly cloudy',
            3 => 'Overcast',
            45 => 'Fog',
            48 => 'Depositing rime fog',
            51 => 'Light drizzle',
            53 => 'Moderate drizzle',
            55 => 'Dense drizzle',
            56 => 'Light freezing drizzle',
            57 => 'Dense freezing drizzle',
            61 => 'Slight rain',
            63 => 'Moderate rain',
            65 => 'Heavy rain',
            66 => 'Light freezing rain',
            67 => 'Heavy freezing rain',
            71 => 'Slight snowfall',
            73 => 'Moderate snowfall',
            75 => 'Heavy snowfall',
            77 => 'Snow grains',
            80 => 'Slight rain showers',
            81 => 'Moderate rain showers',
            82 => 'Violent rain showers',
            85 => 'Slight snow showers',
            86 => 'Heavy snow showers',
            95 => 'Thunderstorm',
            96 => 'Thunderstorm with slight hail',
            99 => 'Thunderstorm with heavy hail',
        ];

        $cases = [];

        foreach ($table as $code => $description) {
            $cases["{$code}: {$description}"] = [$code, $description];
        }

        return $cases;
    }

    public function test_numeric_strings_and_floats_are_accepted(): void
    {
        // Open-Meteo may deliver the code as 61, 61.0 or "61" depending on the encoder.
        $this->assertSame('Slight rain', WeatherCode::describe('61'));
        $this->assertSame('Slight rain', WeatherCode::describe(61.0));
    }

    #[DataProvider('unknownValues')]
    public function test_anything_else_is_reported_as_unknown(mixed $value): void
    {
        $this->assertSame('Unknown weather condition', WeatherCode::describe($value));
    }

    public static function unknownValues(): array
    {
        return [
            'null' => [null],
            'undocumented code' => [4],
            'negative' => [-1],
            'too large' => [100],
            'text' => ['rain'],
            'empty string' => [''],
            'array' => [[61]],
            'boolean' => [true],
        ];
    }
}
