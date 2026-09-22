<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('compatibility_type', 32)
                ->nullable()
                ->after('vehicle_multimedia_system_id')
                ->index();
        });

        Schema::create('product_variant_compatibilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
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
            $table->foreignId('vehicle_multimedia_system_id')->nullable();
            $table->foreign(
                'vehicle_multimedia_system_id',
                'pvc_multimedia_system_fk'
            )->references('id')->on('vehicle_multimedia_systems')->nullOnDelete();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('product_variant_id', 'pvc_variant_idx');
            $table->index('vehicle_brand_id', 'pvc_brand_idx');
            $table->index('vehicle_model_id', 'pvc_model_idx');
            $table->index('vehicle_version_id', 'pvc_version_idx');
            $table->index('vehicle_multimedia_system_id', 'pvc_system_idx');
            $table->index('year_from', 'pvc_year_from_idx');
            $table->index('year_to', 'pvc_year_to_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_compatibilities');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex(['compatibility_type']);
            $table->dropColumn('compatibility_type');
        });
    }
};
