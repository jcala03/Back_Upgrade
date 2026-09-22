<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('quotation_id');
            $table->foreignId('sales_employee_id')->nullable()->after('branch_id');

            $table->index(
                ['branch_id', 'status', 'confirmed_at'],
                'orders_branch_status_confirmed_idx'
            );
            $table->index(
                ['sales_employee_id', 'created_at'],
                'orders_sales_employee_created_idx'
            );

            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->restrictOnDelete();
            $table->foreign('sales_employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['sales_employee_id']);
            $table->dropForeign(['branch_id']);
            $table->dropIndex('orders_sales_employee_created_idx');
            $table->dropIndex('orders_branch_status_confirmed_idx');
            $table->dropColumn(['sales_employee_id', 'branch_id']);
        });
    }
};
