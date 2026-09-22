<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_category_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('cost')->nullable();
            $table->unsignedInteger('estimated_duration_minutes');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();

            $table->index(['service_category_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
