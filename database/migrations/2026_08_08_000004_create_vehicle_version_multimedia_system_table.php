<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_version_multimedia_system', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_version_id')
                ->constrained('vehicle_versions')
                ->cascadeOnDelete();
            $table->foreignId('vehicle_multimedia_system_id');
            $table->foreign(
                'vehicle_multimedia_system_id',
                'vvms_multimedia_system_fk'
            )->references('id')->on('vehicle_multimedia_systems')->cascadeOnDelete();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->timestamps();

            $table->index('vehicle_version_id', 'vvms_version_idx');
            $table->index('vehicle_multimedia_system_id', 'vvms_system_idx');
            $table->index('year_from', 'vvms_year_from_idx');
            $table->index('year_to', 'vvms_year_to_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_version_multimedia_system');
    }
};
