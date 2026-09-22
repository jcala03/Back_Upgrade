<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('assigned_employee_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->index(
                ['branch_id', 'status', 'scheduled_starts_at', 'scheduled_ends_at'],
                'tasks_branch_status_schedule_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_branch_status_schedule_idx');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
