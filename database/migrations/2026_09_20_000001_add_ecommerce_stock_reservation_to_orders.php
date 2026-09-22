<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('stock_reservation_expires_at')->nullable();
            $table->index(['origin', 'status', 'payment_status', 'stock_reservation_expires_at'], 'orders_ecommerce_reservation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_ecommerce_reservation_idx');
            $table->dropColumn('stock_reservation_expires_at');
        });
    }
};
