<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the language for this request: ?lang, then the signed-in person (their own choice, else their
 * company's), then the browser's Accept-Language, then English (never the previous request's language:
 * App::setLocale() overwrites config('app.locale'), which would leak between requests in a long-lived worker). It runs before the route's
 * auth middleware, so it reads the Sanctum user itself.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Locales::normalize($request->query('lang'))
            ?? $this->forPerson($request)
            ?? Locales::fromHeader($request)
            ?? Locales::DEFAULT;

        App::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    private function forPerson(Request $request): ?string
    {
        $user = $request->user('sanctum');

        if ($user === null) {
            return null;
        }

        return Locales::normalize($user->locale) ?? Locales::normalize($user->company?->locale);
    }
}
