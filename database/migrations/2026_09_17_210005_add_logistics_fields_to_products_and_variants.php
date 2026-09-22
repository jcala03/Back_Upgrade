<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('requires_shipping')->default(true)->index();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->char('country_of_origin', 2)->nullable();
            $table->string('hs_code', 32)->nullable();
            $table->string('customs_description', 500)->nullable();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->char('country_of_origin', 2)->nullable();
            $table->string('hs_code', 32)->nullable();
            $table->string('customs_description', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn([
                'weight_grams', 'length_mm', 'width_mm', 'height_mm',
                'country_of_origin', 'hs_code', 'customs_description',
            ]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['requires_shipping']);
            $table->dropColumn([
                'requires_shipping', 'weight_grams', 'length_mm', 'width_mm',
                'height_mm', 'country_of_origin', 'hs_code', 'customs_description',
            ]);
        });
    }
};
