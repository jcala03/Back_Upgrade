<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_notifications', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('severity', 20)->default('info')->after('type');
            $table->string('reference_type', 40)->nullable()->after('data');
            $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');
            $table->string('dedupe_key', 191)->nullable()->after('reference_id');

            $table->index(['user_id', 'read_at', 'created_at'], 'crm_notifications_user_read_created_idx');
            $table->index(['user_id', 'created_at'], 'crm_notifications_user_created_idx');
            $table->index(['reference_type', 'reference_id'], 'crm_notifications_reference_idx');
            $table->unique(['user_id', 'dedupe_key'], 'crm_notifications_user_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::table('crm_notifications', function (Blueprint $table) {
            $table->dropUnique('crm_notifications_user_dedupe_unique');
            $table->dropIndex('crm_notifications_reference_idx');
            $table->dropIndex('crm_notifications_user_created_idx');
            $table->dropIndex('crm_notifications_user_read_created_idx');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['severity', 'reference_type', 'reference_id', 'dedupe_key']);
        });
    }
};
