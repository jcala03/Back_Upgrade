<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_multimedia_systems', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vehicle_brand_id')
                ->constrained('vehicle_brands')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->string('code', 80)->nullable();
            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->unique(['vehicle_brand_id', 'name']);
            $table->unique(['vehicle_brand_id', 'slug']);
            $table->index(['vehicle_brand_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_multimedia_systems');
    }
};
