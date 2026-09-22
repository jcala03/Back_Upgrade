<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'normalized_name')) {
                $table->string('normalized_name')->nullable()->index();
            }

            if (! Schema::hasColumn('products', 'category')) {
                $table->string('category')->nullable();
            }

            if (! Schema::hasColumn('products', 'category_id')) {
                $table->foreignId('category_id')
                    ->nullable()
                    ->constrained('product_categories')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('products', 'product_brand_id')) {
                $table->foreignId('product_brand_id')
                    ->nullable()
                    ->constrained('product_brands')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('products', 'sku')) {
                $table->string('sku')->nullable()->unique();
            }

            if (! Schema::hasColumn('products', 'price')) {
                $table->unsignedBigInteger('price')->default(0);
            }

            if (! Schema::hasColumn('products', 'cost_price')) {
                $table->unsignedBigInteger('cost_price')->default(0);
            }

            if (! Schema::hasColumn('products', 'tax_amount')) {
                $table->unsignedBigInteger('tax_amount')->default(0);
            }

            if (! Schema::hasColumn('products', 'extra_charges')) {
                $table->unsignedBigInteger('extra_charges')->default(0);
            }

            if (! Schema::hasColumn('products', 'total_cost')) {
                $table->unsignedBigInteger('total_cost')->default(0);
            }

            if (! Schema::hasColumn('products', 'profit_amount')) {
                $table->bigInteger('profit_amount')->default(0);
            }

            if (! Schema::hasColumn('products', 'profit_margin_percent')) {
                $table->decimal('profit_margin_percent', 8, 2)->default(0);
            }

            if (! Schema::hasColumn('products', 'markup_percent')) {
                $table->decimal('markup_percent', 8, 2)->default(0);
            }

            if (! Schema::hasColumn('products', 'pricing_mode')) {
                $table->string('pricing_mode', 40)->default('manual');
            }

            if (! Schema::hasColumn('products', 'target_profit_percent')) {
                $table->decimal('target_profit_percent', 8, 2)->nullable();
            }

            if (! Schema::hasColumn('products', 'stock')) {
                $table->integer('stock')->default(0);
            }

            if (! Schema::hasColumn('products', 'initial_stock')) {
                $table->integer('initial_stock')->default(0);
            }

            if (! Schema::hasColumn('products', 'minimum_stock')) {
                $table->integer('minimum_stock')->default(0);
            }

            if (! Schema::hasColumn('products', 'technical_specs')) {
                $table->json('technical_specs')->nullable();
            }

            if (! Schema::hasColumn('products', 'main_image')) {
                $table->string('main_image')->nullable();
            }

            if (! Schema::hasColumn('products', 'is_visible')) {
                $table->boolean('is_visible')->default(false)->index();
            }

            if (! Schema::hasColumn('products', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->index();
            }

            if (! Schema::hasColumn('products', 'is_active')) {
                $table->boolean('is_active')->default(true)->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'normalized_name')) {
                $table->dropColumn('normalized_name');
            }

            if (Schema::hasColumn('products', 'category')) {
                $table->dropColumn('category');
            }

            if (Schema::hasColumn('products', 'category_id')) {
                $table->dropConstrainedForeignId('category_id');
            }

            if (Schema::hasColumn('products', 'product_brand_id')) {
                $table->dropConstrainedForeignId('product_brand_id');
            }

            if (Schema::hasColumn('products', 'sku')) {
                $table->dropColumn('sku');
            }

            if (Schema::hasColumn('products', 'price')) {
                $table->dropColumn('price');
            }

            if (Schema::hasColumn('products', 'cost_price')) {
                $table->dropColumn('cost_price');
            }

            if (Schema::hasColumn('products', 'tax_amount')) {
                $table->dropColumn('tax_amount');
            }

            if (Schema::hasColumn('products', 'extra_charges')) {
                $table->dropColumn('extra_charges');
            }

            if (Schema::hasColumn('products', 'total_cost')) {
                $table->dropColumn('total_cost');
            }

            if (Schema::hasColumn('products', 'profit_amount')) {
                $table->dropColumn('profit_amount');
            }

            if (Schema::hasColumn('products', 'profit_margin_percent')) {
                $table->dropColumn('profit_margin_percent');
            }

            if (Schema::hasColumn('products', 'markup_percent')) {
                $table->dropColumn('markup_percent');
            }

            if (Schema::hasColumn('products', 'pricing_mode')) {
                $table->dropColumn('pricing_mode');
            }

            if (Schema::hasColumn('products', 'target_profit_percent')) {
                $table->dropColumn('target_profit_percent');
            }

            if (Schema::hasColumn('products', 'stock')) {
                $table->dropColumn('stock');
            }

            if (Schema::hasColumn('products', 'initial_stock')) {
                $table->dropColumn('initial_stock');
            }

            if (Schema::hasColumn('products', 'minimum_stock')) {
                $table->dropColumn('minimum_stock');
            }

            if (Schema::hasColumn('products', 'technical_specs')) {
                $table->dropColumn('technical_specs');
            }

            if (Schema::hasColumn('products', 'main_image')) {
                $table->dropColumn('main_image');
            }

            if (Schema::hasColumn('products', 'is_visible')) {
                $table->dropColumn('is_visible');
            }

            if (Schema::hasColumn('products', 'is_featured')) {
                $table->dropColumn('is_featured');
            }

            if (Schema::hasColumn('products', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
