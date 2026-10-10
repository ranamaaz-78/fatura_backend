<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // What a team member may do: a list of "area.action". Null on the owner, who may do everything.
            $table->json('permissions')->nullable()->after('status');
            // The role the owner picked: manager, cashier, accountant, or custom.
            $table->string('team_role', 20)->nullable()->after('permissions');
        });

        // Staff that exist today could do everything: they keep that, as the Manager role.
        DB::table('users')
            ->where('role', 'staff')
            ->whereNull('permissions')
            ->update([
                'permissions' => json_encode(config('permissions.presets.manager')),
                'team_role' => 'manager',
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['permissions', 'team_role']);
        });
    }
};
