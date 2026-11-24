<?php

namespace App\Ai\Agents;

use App\Ai\Tools\CompareCitiesWeather;
use App\Ai\Tools\GetCurrentWeather;
use App\Ai\Tools\GetWeatherForecast;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Stringable;

/**
 * Temperature 0 keeps tool selection deterministic for the small local model.
 * Timeout 180s: qwen2.5:3b needs 70-100s to write multi-day forecasts, past the SDK's 60s default.
 *
 * Conversations are stored by the SDK's RememberConversation middleware, but
 * only when a participant or conversation is set (forParticipant()/continue()).
 * A plain `new WeatherAssistant` stays stateless.
 */
#[Temperature(0)]
#[Timeout(WeatherAssistant::TIMEOUT_SECONDS)]
class WeatherAssistant implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public const TIMEOUT_SECONDS = 180;

    public const HISTORY_MESSAGES = 10;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are a helpful weather assistant.

Your job is to answer weather-related questions using your tools.

Choosing a tool:
- GetCurrentWeather: conditions RIGHT NOW in ONE city. Use it for "now", "currently", "at the moment", or a plain "what is the weather in X".
- GetWeatherForecast: anything in the FUTURE for ONE city. Use it for "tomorrow", "later", "tonight", "this weekend", "this week", "next N days", "will it rain", or today's high and low.
- CompareCitiesWeather: TWO OR MORE cities. Use it for "compare", "X vs Y", "which is warmer/colder/more humid", "is it hotter in X or Y". Put ALL the cities in one call as a list, for example {"cities": ["London", "Ahmedabad"]}. Never call GetCurrentWeather once per city to compare them.
- For GetWeatherForecast set "days" counting today as day 1: tomorrow = 2, this week = 7, the next 5 days = 5. Maximum 7.
- If a question asks about both now and the future for one city, call both GetCurrentWeather and GetWeatherForecast.

Rules:
1. Pass only the city name as "city". If the user names a country, also pass its two-letter code as "country_code" (for example IN, GB, US).
2. Never invent weather information. Only report what the tools return.
3. Never make predictions from GetCurrentWeather; it describes the present moment only.
4. Use the "label" field (Today, Tomorrow, weekday) when describing forecast days.
5. For comparisons, base your answer on the "summary" field. Never calculate differences yourself. Mention any cities listed in "not_found" or "unavailable".
6. Mention the city and country.
7. Give temperatures in Celsius, humidity and chance of rain as percentages, and wind speed with its unit.
8. Keep answers simple and conversational.
9. If a tool cannot find the city, ask the user to check the spelling or mention the country.
10. If weather information is unavailable, explain that clearly.
PROMPT;
    }

    /**
     * How many stored messages (user + assistant rows) are replayed each turn.
     * Kept small: every replayed tool result makes qwen2.5:3b slower.
     */
    protected function maxConversationMessages(): int
    {
        return self::HISTORY_MESSAGES;
    }

    /**
     * Get the tools available to the agent.
     *
     * Resolved through the container so their services are injected,
     * while `new WeatherAssistant` keeps working.
     *
     * @return list<Agent|Tool|ProviderTool>
     */
    public function tools(): iterable
    {
        return [
            app(GetCurrentWeather::class),
            app(GetWeatherForecast::class),
            app(CompareCitiesWeather::class),
        ];
    }
}
