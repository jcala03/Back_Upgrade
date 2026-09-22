<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['origin', 'confirmed_at'], 'orders_origin_confirmed_at_idx');
            $table->index(['created_by', 'confirmed_at'], 'orders_creator_confirmed_at_idx');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['method', 'paid_at'], 'payments_method_paid_at_idx');
            $table->index(['created_by', 'paid_at'], 'payments_creator_paid_at_idx');
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->index(['created_by', 'created_at'], 'quotations_creator_created_at_idx');
            $table->index(['status', 'created_at'], 'quotations_status_created_at_idx');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['product_id', 'order_id'], 'order_items_product_order_idx');
            $table->index(['product_variant_id', 'order_id'], 'order_items_variant_order_idx');
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->index(['product_id', 'created_at'], 'movements_product_created_at_idx');
            $table->index(['product_variant_id', 'created_at'], 'movements_variant_created_at_idx');
            $table->index(['created_by', 'created_at'], 'movements_creator_created_at_idx');
        });
        Schema::table('quotation_status_histories', function (Blueprint $table) {
            $table->index(['to_status', 'created_at'], 'quotation_history_status_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_origin_confirmed_at_idx');
            $table->dropIndex('orders_creator_confirmed_at_idx');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_method_paid_at_idx');
            $table->dropIndex('payments_creator_paid_at_idx');
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex('quotations_creator_created_at_idx');
            $table->dropIndex('quotations_status_created_at_idx');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_product_order_idx');
            $table->dropIndex('order_items_variant_order_idx');
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('movements_product_created_at_idx');
            $table->dropIndex('movements_variant_created_at_idx');
            $table->dropIndex('movements_creator_created_at_idx');
        });
        Schema::table('quotation_status_histories', fn (Blueprint $table) => $table->dropIndex('quotation_history_status_created_at_idx'));
    }
};
