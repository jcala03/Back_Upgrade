<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('status')
                ->constrained('branches')
                ->restrictOnDelete();
            $table->foreignId('sales_employee_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->index(
                ['branch_id', 'status', 'valid_until'],
                'quotations_branch_status_valid_index',
            );
            $table->index(
                ['sales_employee_id', 'created_at'],
                'quotations_sales_employee_created_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropIndex('quotations_branch_status_valid_index');
            $table->dropIndex('quotations_sales_employee_created_index');
            $table->dropConstrainedForeignId('sales_employee_id');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
