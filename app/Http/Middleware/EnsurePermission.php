<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a team member through only when they hold the permission. Several can be listed with "|" (any one is
 * enough), for the screens that look things up for other work: `permission:customers.view|invoices.create`.
 * The owner holds every permission.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
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

        foreach (explode('|', $permission) as $needed) {
            if ($user->hasPermission($needed)) {
                return $next($request);
            }
        }

        return Permissions::denied();
    }
}
