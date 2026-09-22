<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id');
            $table->foreignId('branch_id');
            $table->foreignId('order_id');
            $table->foreignId('order_item_id');
            $table->foreignId('product_id')->nullable();
            $table->foreignId('product_variant_id')->nullable();
            $table->string('product_name_snapshot');
            $table->string('variant_name_snapshot')->nullable();
            $table->string('sku_snapshot')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_commission');
            $table->unsignedBigInteger('amount');
            $table->string('status', 20)->default('pending');
            $table->timestamp('earned_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['order_item_id', 'employee_id'],
                'employee_commissions_item_employee_unique'
            );
            $table->index(
                ['employee_id', 'status', 'earned_at'],
                'employee_commissions_employee_status_earned_idx'
            );
            $table->index(
                ['employee_id', 'created_at'],
                'employee_commissions_employee_created_idx'
            );
            $table->index(
                ['branch_id', 'status', 'earned_at'],
                'employee_commissions_branch_status_earned_idx'
            );
            $table->index(
                ['status', 'created_at'],
                'employee_commissions_status_created_idx'
            );
            $table->index(
                ['product_id', 'earned_at'],
                'employee_commissions_product_earned_idx'
            );

            $table->foreign('employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();
            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign('order_id')
                ->references('id')
                ->on('orders')
                ->restrictOnDelete();
            $table->foreign('order_item_id')
                ->references('id')
                ->on('order_items')
                ->restrictOnDelete();
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->nullOnDelete();
            $table->foreign('product_variant_id')
                ->references('id')
                ->on('product_variants')
                ->nullOnDelete();
            $table->foreign('voided_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_commissions');
    }
};
