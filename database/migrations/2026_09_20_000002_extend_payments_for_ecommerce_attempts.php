<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->char('currency', 3)->default('COP');
            $table->string('idempotency_key', 255)->nullable()->collation('utf8mb4_bin');
            $table->char('idempotency_fingerprint', 64)->nullable();
            $table->string('provider_status', 40)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('failure_code', 120)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('reconciliation_required_at')->nullable();
            $table->string('reconciliation_reason')->nullable();
            $table->unique(['provider', 'reference'], 'payments_provider_reference_unique');
            $table->unique(['provider', 'transaction_id'], 'payments_provider_transaction_unique');
            $table->unique(['provider', 'idempotency_key'], 'payments_provider_idempotency_unique');
            $table->index(['status', 'expires_at'], 'payments_status_expiry_idx');
            $table->index(['provider', 'provider_status'], 'payments_provider_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_provider_reference_unique');
            $table->dropUnique('payments_provider_transaction_unique');
            $table->dropUnique('payments_provider_idempotency_unique');
            $table->dropIndex('payments_status_expiry_idx');
            $table->dropIndex('payments_provider_status_idx');
            $table->dropColumn([
                'currency', 'idempotency_key', 'idempotency_fingerprint', 'provider_status',
                'expires_at', 'failure_code', 'failure_reason', 'reconciliation_required_at', 'reconciliation_reason',
            ]);
        });
    }
};
