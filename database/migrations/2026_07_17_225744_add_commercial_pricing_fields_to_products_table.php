<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('product_categories')
            && ! Schema::hasColumn('products', 'category_id')
        ) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('category_id')
                    ->nullable()
                    ->after('category')
                    ->constrained('product_categories')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('products', 'product_brand_id')) {
            Schema::table('products', function (Blueprint $table) {
                $afterColumn = Schema::hasColumn('products', 'category_id')
                    ? 'category_id'
                    : 'category';

                $table->foreignId('product_brand_id')
                    ->nullable()
                    ->after($afterColumn)
                    ->constrained('product_brands')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('products', 'tax_amount')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedInteger('tax_amount')->default(0)->after('cost_price');
            });
        }

        if (! Schema::hasColumn('products', 'extra_charges')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedInteger('extra_charges')->default(0)->after('tax_amount');
            });
        }

        if (! Schema::hasColumn('products', 'total_cost')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedInteger('total_cost')->default(0)->after('extra_charges');
            });
        }

        if (! Schema::hasColumn('products', 'profit_amount')) {
            Schema::table('products', function (Blueprint $table) {
                $table->integer('profit_amount')->default(0)->after('total_cost');
            });
        }

        if (! Schema::hasColumn('products', 'profit_margin_percent')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('profit_margin_percent', 8, 2)->default(0)->after('profit_amount');
            });
        }

        if (! Schema::hasColumn('products', 'markup_percent')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('markup_percent', 8, 2)->default(0)->after('profit_margin_percent');
            });
        }

        if (! Schema::hasColumn('products', 'pricing_mode')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('pricing_mode', 30)->default('manual')->after('markup_percent');
            });
        }

        if (! Schema::hasColumn('products', 'target_profit_percent')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('target_profit_percent', 8, 2)->nullable()->after('pricing_mode');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'product_brand_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropConstrainedForeignId('product_brand_id');
            });
        }

        $columns = [
            'target_profit_percent',
            'pricing_mode',
            'markup_percent',
            'profit_margin_percent',
            'profit_amount',
            'total_cost',
            'extra_charges',
            'tax_amount',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('products', $column)) {
                Schema::table('products', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
