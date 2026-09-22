<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'stock',
                'initial_stock',
                'minimum_stock',
            ]);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn([
                'stock',
                'initial_stock',
                'minimum_stock',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('initial_stock')->default(0);
            $table->unsignedInteger('minimum_stock')->default(0);
            $table->index('stock');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->integer('stock')->default(0);
            $table->integer('initial_stock')->default(0);
            $table->integer('minimum_stock')->default(0);
        });
    }
};
