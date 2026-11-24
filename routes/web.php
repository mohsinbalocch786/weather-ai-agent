<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ChatController::class, 'show'])->name('chat');

Route::get('/c/{conversation}', [ChatController::class, 'show'])
    ->whereUuid('conversation')
    ->name('conversations.show');

Route::delete('/c/{conversation}', [ChatController::class, 'destroy'])
    ->whereUuid('conversation')
    ->name('conversations.destroy');

// Throttled to protect the local Ollama server from runaway clients.
Route::post('/chat', [ChatController::class, 'stream'])
    ->middleware('throttle:20,1')
    ->name('chat.stream');
