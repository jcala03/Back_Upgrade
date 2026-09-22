<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('phone', 40)->nullable();
            $table->string('job_title', 120)->index();
            $table->string('specialty', 160)->nullable()->index();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->date('hire_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
