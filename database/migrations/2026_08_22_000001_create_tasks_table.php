<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assigned_employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('priority', 30)->default('normal');
            $table->string('status', 30)->default('pending');
            $table->dateTime('due_at')->nullable();
            $table->dateTime('scheduled_starts_at')->nullable();
            $table->dateTime('scheduled_ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->boolean('availability_override')->default(false);
            $table->string('availability_override_reason')->nullable();
            $table->foreignId('availability_overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('availability_overridden_at')->nullable();
            $table->timestamps();
            $table->index(
                ['assigned_employee_id', 'status', 'scheduled_starts_at', 'scheduled_ends_at'],
                'task_employee_scheduled_lookup_index',
            );
            $table->index(['assigned_employee_id', 'status', 'due_at'], 'task_employee_due_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
