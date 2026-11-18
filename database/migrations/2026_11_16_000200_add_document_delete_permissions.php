<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Deleting a document that was never paid is a new "delete" tick on invoices, delivery notes, quotes and proformas.
     * Whoever is at the manager level already (the ticks only a manager has) gets it, in the members' own ticks and in a
     * company's saved version of a role; the owner can untick it. A cashier or an accountant does not gain it.
     */
    private const MARKERS = [
        'invoices.void' => 'invoices.delete',
        'delivery_notes.void' => 'delivery_notes.delete',
        'quotes.update' => 'quotes.delete',
        'proformas.create' => 'proformas.delete',
    ];

    public function up(): void
    {
        $grow = function (array $list): array {
            foreach (self::MARKERS as $marker => $gain) {
                if (in_array($marker, $list, true)) {
                    $list[] = $gain;
                }
            }

            return array_values(array_unique($list));
        };

        DB::table('users')->whereNotNull('permissions')->orderBy('id')->each(function ($user) use ($grow) {
            $list = json_decode($user->permissions, true);

            if (is_array($list)) {
                DB::table('users')->where('id', $user->id)->update(['permissions' => json_encode($grow($list))]);
            }
        });

        DB::table('companies')->whereNotNull('role_presets')->orderBy('id')->each(function ($company) use ($grow) {
            $presets = json_decode($company->role_presets, true);

            if (! is_array($presets)) {
                return;
            }

            foreach ($presets as $role => $list) {
                if (is_array($list)) {
                    $presets[$role] = $grow($list);
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
