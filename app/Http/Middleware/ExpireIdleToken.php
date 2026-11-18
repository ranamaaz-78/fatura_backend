<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a person out after a while of doing nothing. Every request a signed-in person makes marks their token as used;
 * a token that has not been used for the idle time (30 minutes) is deleted, and the person must sign in again.
 *
 * It has to be the very first middleware: the moment anything asks who the user is, Sanctum stamps "last used" as
 * now, which would hide how long the token had really been idle.
 */
class ExpireIdleToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $minutes = (int) config('fatura.session.idle_minutes', 30);

        if ($plain !== null && $minutes > 0 && $request->is('api/*') && ($token = PersonalAccessToken::findToken($plain)) !== null) {
            $lastSeen = $token->last_used_at ?? $token->created_at;

            if ($lastSeen !== null && $lastSeen->lt(now()->subMinutes($minutes))) {
                $token->delete();

                // The language middleware has not run yet: the answer is written in the language the request asked for.
                $locale = $request->getPreferredLanguage(['en', 'es']);
                if ($locale !== null) {
                    App::setLocale($locale);
                }

                return response()->json([
                    'success' => false,
                    'message' => __('You were signed out after :minutes minutes without activity. Please sign in again.', ['minutes' => $minutes]),
                    'data' => [],
                    'code' => 'SESSION_IDLE',
                ], 401);
            }
        }

        return $next($request);
    }
}
