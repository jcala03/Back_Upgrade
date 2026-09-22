<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('product_vehicle_compatibilities');

        Schema::create('product_vehicle_compatibilities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->foreignId('vehicle_brand_id')
                ->constrained('vehicle_brands')
                ->cascadeOnDelete();

            $table->foreignId('vehicle_model_id')
                ->nullable()
                ->constrained('vehicle_models')
                ->nullOnDelete();

            $table->foreignId('vehicle_version_id')
                ->nullable()
                ->constrained('vehicle_versions')
                ->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique([
                'product_id',
                'vehicle_brand_id',
                'vehicle_model_id',
                'vehicle_version_id',
            ], 'product_vehicle_compatibilities_unique');

            $table->index('vehicle_brand_id');
            $table->index('vehicle_model_id');
            $table->index('vehicle_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_vehicle_compatibilities');
    }
};
