<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 30);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('metric_type', 40)->default('manual');
            $table->decimal('target_value', 15, 2)->nullable();
            $table->decimal('current_value', 15, 2)->default(0);
            $table->string('unit', 40)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->string('status', 30)->default('active');
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status', 'due_on']);
            $table->index(['employee_id', 'source', 'status']);
            $table->index(['status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
