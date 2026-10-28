<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Purchases bring in "stock.create" (record a purchase) and "stock.void" (cancel one). Whoever may already change
     * products (the manager level) gets both, in the members' own ticks and in a company's saved version of a role;
     * the owner can untick them. Roles that cannot change products do not gain them.
     */
    public function up(): void
    {
        $gain = ['stock.create', 'stock.void'];

        DB::table('users')->whereNotNull('permissions')->orderBy('id')->each(function ($user) use ($gain) {
            $list = json_decode($user->permissions, true);

            if (is_array($list) && in_array('products.update', $list, true)) {
                DB::table('users')->where('id', $user->id)->update(['permissions' => json_encode(array_values(array_unique([...$list, ...$gain])))]);
            }
        });

        DB::table('companies')->whereNotNull('role_presets')->orderBy('id')->each(function ($company) use ($gain) {
            $presets = json_decode($company->role_presets, true);

            if (! is_array($presets)) {
                return;
            }

            foreach ($presets as $role => $list) {
                if (is_array($list) && in_array('products.update', $list, true)) {
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
