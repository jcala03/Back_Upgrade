<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_schedule_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('type', 30);
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'date'], 'employee_override_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_schedule_overrides');
    }
};
