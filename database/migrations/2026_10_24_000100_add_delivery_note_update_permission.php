<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * An unpaid delivery note can now be edited ("delivery_notes.update"). Whoever may already void delivery notes (the
     * manager level) gets it too, in the members' own ticks and in a company's saved version of a role; the owner can
     * untick it. Roles that cannot void do not gain it.
     */
    public function up(): void
    {
        DB::table('users')->whereNotNull('permissions')->orderBy('id')->each(function ($user) {
            $list = json_decode($user->permissions, true);

            if (is_array($list) && in_array('delivery_notes.void', $list, true) && ! in_array('delivery_notes.update', $list, true)) {
                $list[] = 'delivery_notes.update';
                DB::table('users')->where('id', $user->id)->update(['permissions' => json_encode(array_values($list))]);
            }
        });

        DB::table('companies')->whereNotNull('role_presets')->orderBy('id')->each(function ($company) {
            $presets = json_decode($company->role_presets, true);

            if (! is_array($presets)) {
                return;
            }

            foreach ($presets as $role => $list) {
                if (is_array($list) && in_array('delivery_notes.void', $list, true) && ! in_array('delivery_notes.update', $list, true)) {
                    $presets[$role][] = 'delivery_notes.update';
                }
            }

            DB::table('companies')->where('id', $company->id)->update(['role_presets' => json_encode($presets)]);
        });
    }

    public function down(): void
    {
        // Nothing to put back: an extra tick does no harm.
    }
};
