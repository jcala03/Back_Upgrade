<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_models', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vehicle_brand_id')
                ->constrained('vehicle_brands')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['vehicle_brand_id', 'name']);
            $table->unique(['vehicle_brand_id', 'slug']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_models');
    }
};
