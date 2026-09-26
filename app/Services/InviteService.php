<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Password;

class InviteService
{
    public function createToken(User $user): string
    {
        return Password::broker('invites')->createToken($user);
    }

    public function urlFor(User $user, ?string $token = null): string
    {
        $token ??= $this->createToken($user);

        return rtrim(config('fatura.frontend_url'), '/')
            .'/set-password?token='.$token
            .'&email='.urlencode($user->email);
    }
}
