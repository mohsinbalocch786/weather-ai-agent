<?php

namespace App\Http\Controllers;

use App\Ai\Agents\WeatherAssistant;
use App\Http\Requests\ChatMessageRequest;
use App\Services\Chat\ChatVisitors;
use App\Services\Chat\ConversationHistory;
use App\Services\Chat\WeatherChat;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(
        private readonly ChatVisitors $visitors,
        private readonly ConversationHistory $history,
    ) {}

    /**
     * The chat page: a new conversation, or a stored one when an id is given.
     */
    public function show(Request $request, ?string $conversation = null): View
    {
        $visitor = $this->visitors->find($request);
        $current = $conversation ? $this->history->find($visitor, $conversation) : null;

        abort_if($conversation && ! $current, 404);

        return view('chat', [
            'model' => config('ai.providers.'.config('ai.default').'.models.text.default'),
            'maxLength' => ChatMessageRequest::MAX_LENGTH,
            'conversations' => $this->history->recent($visitor),
            'conversation' => $current,
            'messages' => $current ? $this->history->messages($current) : [],
        ]);
    }

    /**
     * Stream the assistant's reply as Server-Sent Events.
     */
    public function stream(ChatMessageRequest $request, WeatherChat $chat): StreamedResponse
    {
        $visitor = $this->visitors->findOrCreate($request);

        $conversation = $request->conversationId()
            ? $this->history->find($visitor, $request->conversationId())
            : null;

        abort_if($request->conversationId() && ! $conversation, 404);

        $message = $request->chatMessage();

        return response()->stream(function () use ($chat, $message, $visitor, $conversation) {
            // On Windows the web SAPI time limit is wall-clock time; allow the agent's full timeout.
            @set_time_limit(WeatherAssistant::TIMEOUT_SECONDS + 30);

            foreach ($chat->stream($message, $visitor, $conversation) as $event) {
                yield 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE)."\n\n";
            }
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function destroy(Request $request, string $conversation): RedirectResponse
    {
        $owned = $this->history->find($this->visitors->find($request), $conversation);

        abort_unless($owned, 404);

        $this->history->delete($owned);

        return redirect()->route('chat')->with('status', 'Conversation deleted.');
    }
}
