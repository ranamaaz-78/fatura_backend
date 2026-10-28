<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shared demo account is the same for everybody, so what would change it for all of them (the company's details,
 * the team, the password, uploads…) is closed inside it. Everything else stays open: that is the practice.
 */
class NotInDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->company?->is_demo) {
            return response()->json([
                'success' => false,
                'message' => __('This is not available in the demo.'),
                'data' => [],
                'code' => 'DEMO_RESTRICTED',
            ], 403);
        }

        return $next($request);
    }
}
