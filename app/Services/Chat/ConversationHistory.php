<?php

namespace App\Services\Chat;

use App\Models\ChatVisitor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Read and manage a visitor's stored conversations.
 *
 * Every lookup is scoped to the visitor, so one browser can never read,
 * continue or delete another browser's conversation.
 */
class ConversationHistory
{
    /**
     * @return Collection<int, Conversation>
     */
    public function recent(?ChatVisitor $visitor, int $limit = 30): Collection
    {
        if ($visitor === null) {
            return new Collection;
        }

        return $this->ownedBy($visitor)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'title', 'updated_at']);
    }

    public function find(?ChatVisitor $visitor, string $id): ?Conversation
    {
        return $visitor === null ? null : $this->ownedBy($visitor)->whereKey($id)->first();
    }

    /**
     * Stored messages prepared for display.
     *
     * @return list<array{role: string, content: string, tools: list<string>, failed: bool}>
     */
    public function messages(Conversation $conversation): array
    {
        return ConversationMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->orderBy('created_at')
            ->orderBy('id') // uuid7 ids are time-ordered, breaking same-second ties
            ->get()
            ->map(fn (ConversationMessage $message): array => [
                'role' => $message->role,
                'content' => (string) $message->content,
                'tools' => array_map(
                    fn (array $call): string => ToolLabel::describe((string) ($call['name'] ?? ''), (array) ($call['arguments'] ?? [])),
                    $message->role === 'assistant' ? $message->tool_calls : [],
                ),
                'failed' => ($message->status?->value ?? null) === 'failed',
            ])
            ->values()
            ->all();
    }

    public function delete(Conversation $conversation): void
    {
        DB::connection($conversation->getConnectionName())->transaction(function () use ($conversation) {
            ConversationMessage::where('conversation_id', $conversation->getKey())->delete();
            $conversation->delete();
        });
    }

    /**
     * @return Builder<Conversation>
     */
    private function ownedBy(ChatVisitor $visitor)
    {
        return Conversation::query()
            ->where('participant_type', Conversation::participantType($visitor))
            ->where('participant_id', Conversation::participantKey($visitor));
    }
}
