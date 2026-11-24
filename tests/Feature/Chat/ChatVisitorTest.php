<?php

namespace Tests\Feature\Chat;

use App\Models\ChatVisitor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Tests\TestCase;

class ChatVisitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_owns_the_conversations_stored_with_it_as_participant(): void
    {
        $mine = ChatVisitor::create(['token_hash' => hash('sha256', 'a')]);
        $other = ChatVisitor::create(['token_hash' => hash('sha256', 'b')]);

        $this->conversation($mine, 'Mine 1');
        $this->conversation($mine, 'Mine 2');
        $this->conversation($other, 'Not mine');

        $this->assertEqualsCanonicalizing(['Mine 1', 'Mine 2'], $mine->conversations->pluck('title')->all());
        $this->assertSame(['Not mine'], $other->conversations->pluck('title')->all());
    }

    public function test_the_token_hash_is_never_serialized(): void
    {
        $visitor = ChatVisitor::create(['token_hash' => hash('sha256', 'secret')]);

        $this->assertArrayNotHasKey('token_hash', $visitor->toArray());
        $this->assertStringNotContainsString(hash('sha256', 'secret'), $visitor->toJson());
    }

    public function test_the_token_hash_is_unique(): void
    {
        ChatVisitor::create(['token_hash' => hash('sha256', 'same')]);

        $this->expectException(UniqueConstraintViolationException::class);

        ChatVisitor::create(['token_hash' => hash('sha256', 'same')]);
    }

    private function conversation(ChatVisitor $visitor, string $title): void
    {
        Conversation::create([
            'id' => (string) Str::uuid7(),
            'participant_type' => $visitor->getMorphClass(),
            'participant_id' => $visitor->id,
            'title' => $title,
        ]);
    }
}
