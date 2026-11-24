<?php

namespace Tests\Feature\Chat;

use App\Ai\Agents\WeatherAssistant;
use App\Models\ChatVisitor;
use App\Services\Chat\ChatVisitors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Feature\Ai\OpenMeteoFixtures;
use Tests\TestCase;

class ConversationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            'https://geocoding-api.open-meteo.com/*' => fn ($r) => Http::response(OpenMeteoFixtures::geocoding($r['name'])),
            'https://api.open-meteo.com/*' => fn ($r) => Http::response(
                isset($r['daily'])
                    ? OpenMeteoFixtures::londonDaily((int) $r['forecast_days'])
                    : OpenMeteoFixtures::current(OpenMeteoFixtures::cityAt($r['latitude']))
            ),
        ]);
    }

    public function test_the_first_message_creates_a_visitor_and_a_stored_conversation(): void
    {
        WeatherAssistant::fake([
            new ToolCall('call_1', 'GetCurrentWeather', ['city' => 'London']),
            'It is 12.4°C in London.',
        ])->preventStrayPrompts();

        $response = $this->send('What is the weather in London right now? I am planning a walk later.');

        // Visitor + cookie
        $this->assertSame(1, ChatVisitor::count());
        $token = $response->getCookie(ChatVisitors::COOKIE)?->getValue();
        $this->assertNotNull($token);
        $this->assertSame(64, strlen($token));
        $this->assertDatabaseHas('chat_visitors', ['token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('chat_visitors', ['token_hash' => $token]);

        // Conversation owned by that visitor, with the truncated first message as title (no extra model call).
        $conversation = Conversation::sole();
        $this->assertSame(ChatVisitor::first()->getMorphClass(), $conversation->participant_type);
        $this->assertEquals(ChatVisitor::first()->id, $conversation->participant_id);
        $this->assertSame(Str::limit('What is the weather in London right now? I am planning a walk later.', 50, preserveWords: true), $conversation->title);

        // Both turns stored, tool call included.
        $messages = ConversationMessage::orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(['user', 'assistant'], $messages->pluck('role')->all());
        $this->assertSame('It is 12.4°C in London.', $messages[1]->content);
        $this->assertSame('GetCurrentWeather', $messages[1]->tool_calls[0]['name']);

        // The stream tells the browser where the conversation lives.
        $events = $this->events($response);
        $this->assertSame([
            'type' => 'conversation',
            'id' => $conversation->id,
            'title' => $conversation->title,
            'url' => route('conversations.show', $conversation->id),
        ], $events[count($events) - 2]);
        $this->assertSame(['type' => 'done'], end($events));
    }

    public function test_the_visitor_cookie_is_encrypted_http_only_same_site_and_long_lived(): void
    {
        WeatherAssistant::fake(['Hello!']);

        $response = $this->send('Hi');

        $cookie = $response->getCookie(ChatVisitors::COOKIE);
        $raw = $response->getCookie(ChatVisitors::COOKIE, decrypt: false);

        $this->assertTrue($cookie->isHttpOnly(), 'JavaScript must not be able to read the visitor token.');
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNotSame($cookie->getValue(), $raw->getValue(), 'The token must be encrypted in the cookie.');

        // About five years.
        $years = ($cookie->getExpiresTime() - time()) / (365 * 24 * 3600);
        $this->assertEqualsWithDelta(5.0, $years, 0.05);
    }

    public function test_a_follow_up_continues_the_conversation_and_replays_history(): void
    {
        WeatherAssistant::fake([
            new ToolCall('call_1', 'GetCurrentWeather', ['city' => 'London']),
            'It is 12.4°C in London.',
            new ToolCall('call_2', 'GetWeatherForecast', ['city' => 'London', 'days' => 2]),
            'Tomorrow: slight rain.',
        ]);

        $first = $this->send('Weather in London?');
        $token = $first->getCookie(ChatVisitors::COOKIE)->getValue();
        $id = Conversation::sole()->id;

        $second = $this->withCookie(ChatVisitors::COOKIE, $token)->send('And tomorrow?', $id);

        $second->assertOk();
        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, ChatVisitor::count());
        $this->assertSame(4, ConversationMessage::where('conversation_id', $id)->count());

        // The second prompt was sent with the first exchange as history.
        WeatherAssistant::assertPrompted(function (AgentPrompt $prompt) {
            if ($prompt->prompt !== 'And tomorrow?') {
                return false;
            }

            $history = collect($prompt->agent->messages());

            return $history->contains(fn (Message $m) => $m->role->value === 'user' && $m->content === 'Weather in London?')
                && $history->contains(fn (Message $m) => $m->role->value === 'assistant' && str_contains((string) $m->content, '12.4°C'));
        });
    }

    public function test_history_replay_is_limited(): void
    {
        $this->assertSame(10, WeatherAssistant::HISTORY_MESSAGES);

        $visitor = ChatVisitor::create(['token_hash' => hash('sha256', 'x')]);
        $id = $this->storeConversation($visitor, 'Long chat', turns: 8); // 16 rows

        $agent = (new WeatherAssistant)->continue($id, as: $visitor);

        $this->assertCount(10, $agent->messages());
    }

    public function test_a_stored_conversation_is_shown_with_its_messages_and_tool_labels(): void
    {
        WeatherAssistant::fake([
            new ToolCall('call_1', 'CompareCitiesWeather', ['cities' => ['London', 'Ahmedabad']]),
            'Ahmedabad is **18.4°C warmer** than London.',
        ]);

        $token = $this->send('Is it warmer in London or Ahmedabad?')->getCookie(ChatVisitors::COOKIE)->getValue();
        $id = Conversation::sole()->id;

        $this->withCookie(ChatVisitors::COOKIE, $token)
            ->get(route('conversations.show', $id))
            ->assertOk()
            ->assertSee('Is it warmer in London or Ahmedabad?')
            ->assertSee('Ahmedabad is **18.4°C warmer** than London.')
            ->assertSee('Comparing London and Ahmedabad')
            ->assertSee('value="'.$id.'"', false)
            ->assertSee('Delete conversation');
    }

    public function test_the_sidebar_lists_only_the_visitors_own_conversations_newest_first(): void
    {
        [$mine, $token] = $this->visitorWithToken();
        $other = ChatVisitor::create(['token_hash' => hash('sha256', Str::random(64))]);

        $old = $this->storeConversation($mine, 'Old chat about Rome');
        $this->travel(5)->minutes();
        $new = $this->storeConversation($mine, 'New chat about Paris');
        $this->storeConversation($other, 'Somebody else in Tokyo');

        $this->withCookie(ChatVisitors::COOKIE, $token)
            ->get('/')
            ->assertOk()
            ->assertSeeInOrder(['New chat about Paris', 'Old chat about Rome'])
            ->assertSee(route('conversations.show', $new))
            ->assertSee(route('conversations.show', $old))
            ->assertDontSee('Somebody else in Tokyo');
    }

    public function test_a_new_browser_sees_no_conversations_and_gets_no_visitor_until_it_sends_a_message(): void
    {
        $this->storeConversation(ChatVisitor::create(['token_hash' => hash('sha256', 'x')]), 'Private chat');

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Your conversations will appear here.')
            ->assertDontSee('Private chat')
            ->assertCookieMissing(ChatVisitors::COOKIE);
        $this->assertSame(1, ChatVisitor::count());
    }

    public function test_another_visitor_cannot_view_continue_or_delete_a_conversation(): void
    {
        [$owner] = $this->visitorWithToken();
        [, $intruderToken] = $this->visitorWithToken();
        $id = $this->storeConversation($owner, 'Owner only');

        WeatherAssistant::fake()->preventStrayPrompts();

        $this->withCookie(ChatVisitors::COOKIE, $intruderToken)->get(route('conversations.show', $id))->assertNotFound();
        $this->withCookie(ChatVisitors::COOKIE, $intruderToken)->send('Hi', $id)->assertNotFound();
        $this->withCookie(ChatVisitors::COOKIE, $intruderToken)->delete(route('conversations.destroy', $id))->assertNotFound();

        // No cookie at all behaves the same.
        $this->get(route('conversations.show', $id))->assertNotFound();

        $this->assertTrue(Conversation::whereKey($id)->exists());
        WeatherAssistant::assertNeverPrompted();
    }

    public function test_a_tampered_or_unknown_cookie_is_treated_as_a_new_visitor(): void
    {
        WeatherAssistant::fake(['Hello!']);

        $response = $this->withCookie(ChatVisitors::COOKIE, str_repeat('z', 64))->send('Hi');

        $response->assertOk();
        $this->assertSame(1, ChatVisitor::count());
        $this->assertNotSame(str_repeat('z', 64), $response->getCookie(ChatVisitors::COOKIE)->getValue());
    }

    public function test_an_unknown_or_malformed_conversation_id_is_rejected(): void
    {
        WeatherAssistant::fake()->preventStrayPrompts();

        $this->send('Hi', (string) Str::uuid7())->assertNotFound();
        $this->send('Hi', 'not-a-uuid')->assertUnprocessable()->assertJsonValidationErrors('conversation');
        $this->get('/c/not-a-uuid')->assertNotFound();

        WeatherAssistant::assertNeverPrompted();
    }

    public function test_the_owner_can_delete_a_conversation(): void
    {
        [$visitor, $token] = $this->visitorWithToken();
        $id = $this->storeConversation($visitor, 'Delete me', turns: 2);
        $keep = $this->storeConversation($visitor, 'Keep me');

        $this->withCookie(ChatVisitors::COOKIE, $token)
            ->delete(route('conversations.destroy', $id))
            ->assertRedirect(route('chat'))
            ->assertSessionHas('status', 'Conversation deleted.');

        $this->assertFalse(Conversation::whereKey($id)->exists());
        $this->assertSame(0, ConversationMessage::where('conversation_id', $id)->count());
        $this->assertTrue(Conversation::whereKey($keep)->exists());
    }

    public function test_a_failed_reply_does_not_break_the_conversation_page(): void
    {
        WeatherAssistant::fake([
            new ToolCall('call_1', 'GetCurrentWeather', ['city' => 'London']),
            fn () => throw new \RuntimeException('model crashed'),
        ]);

        $response = $this->send('Weather in London?');
        $this->assertSame('error', collect($this->events($response))->last()['type']);

        // The SDK records the failed turn; the page must still render it.
        $conversation = Conversation::first();
        $this->assertNotNull($conversation, 'Expected the SDK to store the failed turn.');

        $this->withCookie(ChatVisitors::COOKIE, $response->getCookie(ChatVisitors::COOKIE)->getValue())
            ->get(route('conversations.show', $conversation->id))
            ->assertOk()
            ->assertSee('Weather in London?')
            ->assertSee('This reply did not finish.');
    }

    public function test_a_plain_agent_without_a_participant_stores_nothing(): void
    {
        WeatherAssistant::fake(['Stateless answer.']);

        (new WeatherAssistant)->prompt('Weather in London?');

        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, ConversationMessage::count());
    }

    private function send(string $message, ?string $conversation = null): TestResponse
    {
        // Browsers send cookies with same-origin fetch(); Laravel's JSON test helpers only do with credentials.
        $response = $this->withCredentials()->postJson(route('chat.stream'), array_filter([
            'message' => $message,
            'conversation' => $conversation,
        ]));

        // A StreamedResponse only runs (and so only stores the turn) once its body is read,
        // which a real HTTP request does immediately. streamedContent() caches the body.
        if (str_starts_with((string) $response->headers->get('Content-Type'), 'text/event-stream')) {
            $response->streamedContent();
        }

        return $response;
    }

    /**
     * @return array{0: ChatVisitor, 1: string}
     */
    private function visitorWithToken(): array
    {
        $token = Str::random(64);

        return [ChatVisitor::create(['token_hash' => hash('sha256', $token)]), $token];
    }

    private function storeConversation(ChatVisitor $visitor, string $title, int $turns = 1): string
    {
        $id = (string) Str::uuid7();
        $now = now();

        Conversation::create([
            'id' => $id,
            'participant_type' => $visitor->getMorphClass(),
            'participant_id' => $visitor->id,
            'title' => $title,
        ]);

        for ($i = 0; $i < $turns * 2; $i++) {
            ConversationMessage::create([
                'id' => (string) Str::uuid7(),
                'conversation_id' => $id,
                'participant_type' => $visitor->getMorphClass(),
                'participant_id' => $visitor->id,
                'agent' => WeatherAssistant::class,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
                'attachments' => [],
                // Assistant rows are replayed from their steps; user rows ignore them.
                'steps' => $i % 2 === 0 ? [] : [['content' => "Message {$i}", 'tool_calls' => [], 'reasoning' => '', 'replay_blocks' => [], 'provider_tool_calls' => []]],
                'usage' => [],
                'meta' => [],
                'status' => 'completed',
                'created_at' => $now->copy()->addSeconds($i),
                'updated_at' => $now->copy()->addSeconds($i),
            ]);
        }

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(TestResponse $response): array
    {
        $events = [];

        foreach (explode("\n\n", trim($response->streamedContent())) as $block) {
            if (str_starts_with($block, 'data: ')) {
                $events[] = json_decode(substr($block, 6), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $events;
    }
}
