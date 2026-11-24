<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChatMessageRequest extends FormRequest
{
    public const MAX_LENGTH = 1000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ownership of "conversation" is checked by the controller (404 if not yours).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.self::MAX_LENGTH],
            'conversation' => ['nullable', 'uuid'],
        ];
    }

    public function chatMessage(): string
    {
        return trim($this->validated('message'));
    }

    public function conversationId(): ?string
    {
        return $this->validated('conversation');
    }
}
