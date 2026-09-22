<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Product::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('vehicle_multimedia_system_id')
                ->nullable()
                ->constrained('vehicle_multimedia_systems')
                ->nullOnDelete();

            $table->string('name');
            $table->string('normalized_name')->index();
            $table->string('sku')->nullable()->unique();

            $table->json('attributes')->nullable();

            $table->unsignedBigInteger('cost_price')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('extra_charges')->default(0);
            $table->unsignedBigInteger('total_cost')->default(0);

            $table->unsignedBigInteger('price')->default(0);
            $table->bigInteger('profit_amount')->default(0);
            $table->decimal('profit_margin_percent', 8, 2)->default(0);
            $table->decimal('markup_percent', 8, 2)->default(0);

            $table->string('pricing_mode', 40)->default('manual');
            $table->decimal('target_profit_percent', 8, 2)->nullable();

            $table->integer('stock')->default(0);
            $table->integer('initial_stock')->default(0);
            $table->integer('minimum_stock')->default(0);

            $table->string('main_image')->nullable();

            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_visible')->default(true)->index();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['product_id', 'is_active']);
            $table->index(['product_id', 'is_visible']);
            $table->index(['product_id', 'is_default']);
            $table->index(['vehicle_multimedia_system_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
