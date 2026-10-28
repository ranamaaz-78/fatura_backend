<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\DemoSession;
use App\Services\Demo\DemoCompany;
use App\Services\Demo\DemoResetter;
use App\Services\Demo\DemoSwitch;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets a customer practice in the shared demo account. They go in with their own login: the server gives them a
 * short-lived token of the demo owner (which has no password), and they put their own token back when they leave.
 */
class DemoController extends Controller
{
    use ApiResponse;

    /** How long one visit to the demo lasts before the customer is put back on their own account. */
    public const HOURS = 6;

    public function __construct(private readonly DemoCompany $demo, private readonly DemoResetter $resetter, private readonly DemoSwitch $switch) {}

    public function enter(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->switch->enabled()) {
            return $this->error(__('The demo is not available right now.'), 403);
        }

        if ($user->company?->is_demo) {
            return $this->error(__('You are already in the demo.'), 422);
        }

        // The first visit ever builds the demo; after that it is only the nightly reset that rewrites it.
        $company = $this->demo->find() ?? $this->resetter->reset();
        $owner = $this->demo->owner($company);

        // One demo visit per customer at a time: a new one replaces the old.
        $name = 'demo:'.$user->id;
        $owner->tokens()->where('name', $name)->delete();
        $token = $owner->createToken($name, ['*'], now()->addHours(self::HOURS));

        DemoSession::create([
            'user_id' => $user->id,
            'token_id' => $token->accessToken->id,
            'entered_at' => now(),
            'ip' => $request->ip(),
        ]);

        return $this->success([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => new UserResource($owner),
            'role' => $owner->role->value,
        ], __('Welcome to the demo.'));
    }

    public function exit(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // Only a demo visit ends here; a real login is signed out through the usual logout.
        if ($request->user()->company?->is_demo && $token !== null && str_starts_with((string) $token->name, 'demo:')) {
            DemoSession::query()->where('token_id', $token->id)->whereNull('exited_at')->update(['exited_at' => now()]);
            $token->delete();
        }

        return $this->success([], __('You left the demo.'));
    }
}
