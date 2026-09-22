<?php

use App\Models\ProductVariant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_movements', 'product_variant_id')) {
                $table->foreignIdFor(ProductVariant::class)
                    ->nullable()
                    ->after('product_id')
                    ->constrained()
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_movements', 'product_variant_id')) {
                $table->dropConstrainedForeignId('product_variant_id');
            }
        });
    }
};
