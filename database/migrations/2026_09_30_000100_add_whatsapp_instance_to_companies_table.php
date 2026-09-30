<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('whatsapp_instance_name')->nullable()->after('whatsapp');
            $table->string('whatsapp_status')->default('disconnected')->after('whatsapp_instance_name');
            $table->string('whatsapp_connected_phone')->nullable()->after('whatsapp_status');
            $table->string('whatsapp_connected_name')->nullable()->after('whatsapp_connected_phone');
            $table->boolean('whatsapp_auto_send')->default(true)->after('whatsapp_connected_name');
            $table->text('whatsapp_message_template')->nullable()->after('whatsapp_auto_send');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_instance_name',
                'whatsapp_status',
                'whatsapp_connected_phone',
                'whatsapp_connected_name',
                'whatsapp_auto_send',
                'whatsapp_message_template',
            ]);
        });
    }
};
