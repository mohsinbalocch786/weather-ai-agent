<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Laravel\Ai\Models\Conversation;

/**
 * An anonymous browser that owns chat conversations (there is no login).
 *
 * @property int $id
 * @property string $token_hash
 * @property Carbon|null $last_seen_at
 */
class ChatVisitor extends Model
{
    protected $fillable = ['token_hash', 'last_seen_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /**
     * Conversations stored by the Laravel AI SDK with this visitor as participant.
     */
    public function conversations(): MorphMany
    {
        return $this->morphMany(Conversation::class, 'participant');
    }
}
