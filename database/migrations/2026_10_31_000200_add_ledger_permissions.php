<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The supplier ledger brings in the "ledger" area (view, create, pay, void). Whoever may already record
     * purchases ("stock.create": the manager level) gets all four, in the members' own ticks and in a company's saved
     * version of a role; the owner can untick them. Roles that cannot record purchases do not gain them.
     */
    public function up(): void
    {
        $gain = ['ledger.view', 'ledger.create', 'ledger.pay', 'ledger.void'];

        DB::table('users')->whereNotNull('permissions')->orderBy('id')->each(function ($user) use ($gain) {
            $list = json_decode($user->permissions, true);

            if (is_array($list) && in_array('stock.create', $list, true)) {
                DB::table('users')->where('id', $user->id)->update(['permissions' => json_encode(array_values(array_unique([...$list, ...$gain])))]);
            }
        });

        DB::table('companies')->whereNotNull('role_presets')->orderBy('id')->each(function ($company) use ($gain) {
            $presets = json_decode($company->role_presets, true);

            if (! is_array($presets)) {
                return;
            }

            foreach ($presets as $role => $list) {
                if (is_array($list) && in_array('stock.create', $list, true)) {
                    $presets[$role] = array_values(array_unique([...$list, ...$gain]));
                }
            }

            DB::table('companies')->where('id', $company->id)->update(['role_presets' => json_encode($presets)]);
        });
    }

    public function down(): void
    {
        // Nothing to put back: extra ticks do no harm.
    }
};
