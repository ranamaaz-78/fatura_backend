<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => __('Unauthenticated.'),
                'data' => [],
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        if (! in_array($user->role->value, $roles, true)) {
            return response()->json([
                'success' => false,
                'message' => __('You are not allowed to access this area.'),
                'data' => [],
                'code' => 'FORBIDDEN_ROLE',
            ], 403);
        }

        return $next($request);
    }
}
