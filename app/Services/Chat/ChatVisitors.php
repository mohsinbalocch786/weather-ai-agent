<?php

namespace App\Services\Chat;

use App\Models\ChatVisitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Identifies the anonymous visitor behind a request via a long-lived cookie.
 *
 * The cookie holds a random token (encrypted by Laravel's EncryptCookies
 * middleware); only its SHA-256 hash is stored in the database.
 */
class ChatVisitors
{
    public const COOKIE = 'weather_visitor';

    /** Five years, in minutes. */
    private const COOKIE_LIFETIME = 60 * 24 * 365 * 5;

    /**
     * The visitor for this request, if the browser already has one.
     */
    public function find(Request $request): ?ChatVisitor
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || strlen($token) !== 64) {
            return null;
        }

        $visitor = ChatVisitor::where('token_hash', hash('sha256', $token))->first();

        if ($visitor && (! $visitor->last_seen_at || $visitor->last_seen_at->lt(now()->subHour()))) {
            $visitor->forceFill(['last_seen_at' => now()])->save();
        }

        return $visitor;
    }

    /**
     * The visitor for this request, creating one (and queueing its cookie) if needed.
     */
    public function findOrCreate(Request $request): ChatVisitor
    {
        if ($visitor = $this->find($request)) {
            return $visitor;
        }

        $token = Str::random(64);

        $visitor = ChatVisitor::create([
            'token_hash' => hash('sha256', $token),
            'last_seen_at' => now(),
        ]);

        Cookie::queue(Cookie::make(
            self::COOKIE,
            $token,
            self::COOKIE_LIFETIME,
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        ));

        return $visitor;
    }
}
