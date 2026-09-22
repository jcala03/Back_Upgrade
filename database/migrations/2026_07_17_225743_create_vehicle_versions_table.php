<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vehicle_model_id')
                ->constrained('vehicle_models')
                ->cascadeOnDelete();

            $table->string('name')->nullable();
            $table->unsignedSmallInteger('year_from');
            $table->unsignedSmallInteger('year_to')->nullable();

            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique([
                'vehicle_model_id',
                'name',
                'year_from',
                'year_to',
            ], 'vehicle_versions_unique_version');

            $table->index('year_from');
            $table->index('year_to');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_versions');
    }
};
