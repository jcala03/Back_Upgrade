<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 30);
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status', 'starts_at', 'ends_at'], 'employee_leave_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leaves');
    }
};
