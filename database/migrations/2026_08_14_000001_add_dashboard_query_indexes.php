<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'confirmed_at'], 'orders_status_confirmed_at_idx');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['status', 'paid_at'], 'payments_status_paid_at_idx');
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->index(['status', 'valid_until'], 'quotations_status_valid_until_idx');
            $table->index(['status', 'converted_at'], 'quotations_status_converted_at_idx');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->index(['is_active', 'created_at'], 'customers_active_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropIndex('orders_status_confirmed_at_idx'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex('payments_status_paid_at_idx'));
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex('quotations_status_valid_until_idx');
            $table->dropIndex('quotations_status_converted_at_idx');
        });
        Schema::table('customers', fn (Blueprint $table) => $table->dropIndex('customers_active_created_at_idx'));
    }
};
