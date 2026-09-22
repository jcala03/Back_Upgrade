<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_category_fields', function (Blueprint $table) {
            if (! Schema::hasColumn('product_category_fields', 'is_filterable')) {
                $table->boolean('is_filterable')->default(false)->after('is_active');
            }

            if (! Schema::hasColumn('product_category_fields', 'filter_label')) {
                $table->string('filter_label')->nullable()->after('is_filterable');
            }

            if (! Schema::hasColumn('product_category_fields', 'filter_unit')) {
                $table->string('filter_unit', 30)->nullable()->after('filter_label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_category_fields', function (Blueprint $table) {
            foreach (['filter_unit', 'filter_label', 'is_filterable'] as $column) {
                if (Schema::hasColumn('product_category_fields', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
