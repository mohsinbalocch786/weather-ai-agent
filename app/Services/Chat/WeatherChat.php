<?php

namespace App\Services\Chat;

use App\Ai\Agents\WeatherAssistant;
use App\Models\ChatVisitor;
use Generator;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Streaming\Events\Error as ErrorEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Throwable;

/**
 * Streams a WeatherAssistant reply as small, UI-safe events and stores the turn.
 *
 * The SDK's raw stream events carry full tool payloads and internal ids;
 * the browser only needs text deltas, which tool is running, and errors.
 * Persistence is done by the SDK's RememberConversation middleware once the
 * stream has been fully consumed.
 */
class WeatherChat
{
    public function __construct(private readonly WeatherAssistant $agent) {}

    /**
     * @return Generator<int, array{type: string}&array<string, mixed>>
     */
    public function stream(string $message, ChatVisitor $visitor, ?Conversation $conversation = null): Generator
    {
        $agent = $conversation
            ? $this->agent->continue($conversation->getKey(), as: $visitor)
            : $this->agent->forParticipant($visitor);

        $startedAt = microtime(true);
        $tools = [];

        try {
            foreach ($agent->stream($message) as $event) {
                $mapped = $this->map($event);

                if ($mapped === null) {
                    continue;
                }

                if ($mapped['type'] === 'tool') {
                    $tools[] = $mapped['name'];
                }

                yield $mapped;
            }
        } catch (Throwable $e) {
            Log::error('Weather chat: stream failed', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'tools' => $tools,
                'conversation' => $agent->currentConversation(),
            ]);

            yield ['type' => 'error', 'message' => $this->friendlyError($e)];

            return;
        }

        $conversationId = $agent->currentConversation();

        Log::info('Weather chat: reply streamed', [
            'conversation' => $conversationId,
            'tools' => $tools,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        if ($conversationId !== null) {
            yield [
                'type' => 'conversation',
                'id' => $conversationId,
                'title' => Conversation::whereKey($conversationId)->value('title'),
                'url' => route('conversations.show', $conversationId),
            ];
        }

        yield ['type' => 'done'];
    }

    /**
     * @return (array{type: string}&array<string, mixed>)|null
     */
    private function map(object $event): ?array
    {
        return match (true) {
            $event instanceof TextDelta => $event->delta === '' ? null : ['type' => 'text', 'delta' => $event->delta],
            $event instanceof ToolCall => [
                'type' => 'tool',
                'name' => $event->toolCall->name,
                'label' => ToolLabel::describe($event->toolCall->name, $event->toolCall->arguments),
            ],
            $event instanceof ToolResult => [
                'type' => 'tool_done',
                'name' => $event->toolResult->name,
                'successful' => $event->successful,
            ],
            $event instanceof ErrorEvent => tap(
                ['type' => 'error', 'message' => 'The AI model reported an error. Please try again.'],
                fn () => Log::warning('Weather chat: provider error event', ['type' => $event->type, 'message' => $event->message]),
            ),
            default => null,
        };
    }

    private function friendlyError(Throwable $e): string
    {
        return $e instanceof ProviderConnectionException
            ? 'The AI model is not reachable right now. Please check that Ollama is running and try again.'
            : 'Something went wrong while answering. Please try again.';
    }
}
