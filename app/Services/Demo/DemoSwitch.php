<?php

namespace App\Services\Demo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

/** The super admin's on / off switch for the demo account. On by default; turning it off also sends visitors out. */
class DemoSwitch
{
    private const KEY = 'demo_enabled';

    public function enabled(): bool
    {
        // Until the table is migrated the demo is simply on: a missing table must never get in the way of logging in.
        if (! Schema::hasTable('platform_settings')) {
            return true;
        }

        return DB::table('platform_settings')->where('key', self::KEY)->value('value') !== '0';
    }

    public function set(bool $enabled): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => $enabled ? '1' : '0', 'updated_at' => now(), 'created_at' => now()],
        );

        // Whoever is inside right now goes back to their own account the next time the app asks the server anything.
        if (! $enabled) {
            PersonalAccessToken::query()->where('name', 'like', 'demo:%')->delete();
        }
    }
}
