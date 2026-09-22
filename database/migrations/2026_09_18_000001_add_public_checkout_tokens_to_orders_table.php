<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->char('public_token_hash', 64)->nullable()->unique()->after('order_number');
            $table->string('checkout_idempotency_key', 255)->nullable()->unique()->after('public_token_hash');
            $table->char('checkout_idempotency_fingerprint', 64)->nullable()->after('checkout_idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['public_token_hash']);
            $table->dropUnique(['checkout_idempotency_key']);
            $table->dropColumn(['public_token_hash', 'checkout_idempotency_key', 'checkout_idempotency_fingerprint']);
        });
    }
};
