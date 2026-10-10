<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Invoices, delivery notes and proformas cannot be edited, so their ticks are "pay" and "void", not "update" and "delete". */
    public function up(): void
    {
        DB::table('users')->whereNotNull('permissions')->orderBy('id')->each(function ($user) {
            $list = json_decode($user->permissions, true);

            if (is_array($list)) {
                DB::table('users')->where('id', $user->id)->update(['permissions' => json_encode(Permissions::migrateLegacy($list))]);
            }
        });

        DB::table('companies')->whereNotNull('role_presets')->orderBy('id')->each(function ($company) {
            $presets = json_decode($company->role_presets, true);

            if (is_array($presets)) {
                $moved = array_map(fn ($list) => is_array($list) ? Permissions::migrateLegacy($list) : $list, $presets);
                DB::table('companies')->where('id', $company->id)->update(['role_presets' => json_encode($moved)]);
            }
        });
    }

    public function down(): void
    {
        // The old names carried a different meaning; there is nothing to put back.
    }
};
