<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\WeatherAssistant;
use App\Ai\Tools\CompareCitiesWeather;
use App\Ai\Tools\GetCurrentWeather;
use App\Ai\Tools\GetWeatherForecast;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\TestCase;

class WeatherAssistantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            'https://geocoding-api.open-meteo.com/*' => fn ($request) => Http::response(OpenMeteoFixtures::geocoding($request['name'])),
            'https://api.open-meteo.com/*' => fn ($request) => Http::response(
                isset($request['daily'])
                    ? OpenMeteoFixtures::londonDaily((int) $request['forecast_days'])
                    : OpenMeteoFixtures::current(OpenMeteoFixtures::cityAt($request['latitude']))
            ),
        ]);
    }

    public function test_it_exposes_the_current_forecast_and_comparison_tools(): void
    {
        $tools = iterator_to_array((function () {
            yield from (new WeatherAssistant)->tools();
        })(), false);

        $this->assertSame(
            [GetCurrentWeather::class, GetWeatherForecast::class, CompareCitiesWeather::class],
            array_map(fn ($tool) => $tool::class, $tools),
        );
    }

    public function test_instructions_and_descriptions_separate_now_from_the_future(): void
    {
        $instructions = (string) (new WeatherAssistant)->instructions();

        $this->assertStringContainsString('GetCurrentWeather: conditions RIGHT NOW', $instructions);
        $this->assertStringContainsString('GetWeatherForecast: anything in the FUTURE', $instructions);
        $this->assertStringContainsString('Do not use it for tomorrow', (string) app(GetCurrentWeather::class)->description());
        $this->assertStringContainsString('tomorrow', (string) app(GetWeatherForecast::class)->description());
        $this->assertStringContainsString('CompareCitiesWeather: TWO OR MORE cities', $instructions);
        $this->assertStringContainsString('Never call GetCurrentWeather once per city', $instructions);
    }

    public function test_it_answers_a_comparison_question_from_the_comparison_tool(): void
    {
        WeatherAssistant::fake([
            new ToolCall('call_1', 'CompareCitiesWeather', ['cities' => ['London', 'Ahmedabad']]),
            'Ahmedabad is 18.4°C warmer than London right now.',
        ])->preventStrayPrompts();

        $response = (new WeatherAssistant)->prompt('Is it warmer in London or Ahmedabad?');

        $this->assertSame(['CompareCitiesWeather'], $response->toolCalls->pluck('name')->all());
        $this->assertSame('Ahmedabad is 18.4°C warmer than London right now.', $response->text);

        $payload = json_decode($response->toolResults->first()->result, true);
        $this->assertSame('Ahmedabad', $payload['comparison']['warmest']['city']);
        $this->assertStringStartsWith('Ahmedabad (30.8°C) is 18.4°C warmer than London (12.4°C).', $payload['comparison']['summary']);
        Http::assertSentCount(4);
    }

    public function test_it_answers_a_current_weather_question_from_the_current_tool(): void
    {
        WeatherAssistant::fake([
            new ToolCall('call_1', 'GetCurrentWeather', ['city' => 'London']),
            'It is 12.4°C and partly cloudy in London, United Kingdom.',
        ])->preventStrayPrompts();

        $response = (new WeatherAssistant)->prompt('What is the weather in London right now?');

        $this->assertSame('It is 12.4°C and partly cloudy in London, United Kingdom.', $response->text);
        $this->assertSame(['GetCurrentWeather'], $response->toolCalls->pluck('name')->all());

        $payload = json_decode($response->toolResults->first()->result, true);
        $this->assertSame('London', $payload['location']['city']);
        $this->assertSame(12.4, $payload['current_weather']['temperature']['value']);

        Http::assertSentCount(2);
        WeatherAssistant::assertPrompted('What is the weather in London right now?');
    }

    public function test_it_answers_a_forecast_question_from_the_forecast_tool(): void
    {
        WeatherAssistant::fake([
            new ToolCall('call_1', 'GetWeatherForecast', ['city' => 'London', 'days' => 2]),
            'Tomorrow in London expect slight rain, 6-11°C, with an 85% chance of rain.',
        ])->preventStrayPrompts();

        $response = (new WeatherAssistant)->prompt('Will it rain in London tomorrow?');

        $this->assertSame(['GetWeatherForecast'], $response->toolCalls->pluck('name')->all());

        $payload = json_decode($response->toolResults->first()->result, true);
        $this->assertCount(2, $payload['forecast']);
        $this->assertSame('Tomorrow', $payload['forecast'][1]['label']);
        $this->assertSame(85, $payload['forecast'][1]['precipitation_probability_max']);
    }

    public function test_a_validation_error_is_returned_to_the_model_so_it_can_retry(): void
    {
        WeatherAssistant::fake([
            new ToolCall('call_1', 'GetWeatherForecast', ['city' => 'London', 'days' => 30]),
            new ToolCall('call_2', 'GetWeatherForecast', ['city' => 'London', 'days' => 7]),
            'Here is the 7-day forecast for London.',
        ])->preventStrayPrompts();

        $response = (new WeatherAssistant)->prompt('Weather in London for the next month?');

        $this->assertCount(2, $response->toolResults);
        $this->assertStringContainsString('days', $response->toolResults[0]->result);
        $this->assertStringContainsString('between 1 and 7', $response->toolResults[0]->result);
        $this->assertSame('Here is the 7-day forecast for London.', $response->text);
    }
}
