<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->string('category')->nullable();
            $table->string('sku')->nullable()->unique();

            $table->unsignedInteger('price');
            $table->unsignedInteger('cost_price')->default(0);

            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('initial_stock')->default(0);
            $table->unsignedInteger('minimum_stock')->default(0);

            $table->string('main_image')->nullable();

            $table->boolean('is_visible')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('category');
            $table->index('sku');
            $table->index('is_visible');
            $table->index('is_featured');
            $table->index('is_active');
            $table->index('stock');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
