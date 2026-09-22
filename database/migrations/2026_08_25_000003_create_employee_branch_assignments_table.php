<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_branch_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id');
            $table->foreignId('from_branch_id')->nullable();
            $table->foreignId('to_branch_id')->nullable();
            $table->foreignId('changed_by')->nullable();
            $table->string('reason', 500)->nullable();
            $table->dateTime('changed_at');
            $table->timestamps();

            $table->index(['employee_id', 'changed_at']);
            $table->foreign('employee_id')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('from_branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('to_branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_branch_assignments');
    }
};
