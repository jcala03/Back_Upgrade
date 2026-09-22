<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('responsible_employee_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->index(
                ['branch_id', 'status', 'starts_at', 'ends_at'],
                'appointments_branch_status_schedule_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_branch_status_schedule_idx');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
