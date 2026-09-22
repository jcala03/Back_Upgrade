<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->string('product_sku')->nullable()->after('product_slug');
            $table->string('variant_name')->nullable()->after('product_sku');
            $table->string('variant_sku')->nullable()->after('variant_name');
            $table->json('variant_specs')->nullable()->after('variant_sku');
            $table->unsignedBigInteger('unit_cost')->nullable()->after('unit_price');
            $table->unsignedBigInteger('subtotal')->nullable()->after('quantity');
            $table->unsignedBigInteger('discount_amount')->default(0)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn([
                'product_sku', 'variant_name', 'variant_sku', 'variant_specs',
                'unit_cost', 'subtotal', 'discount_amount',
            ]);
        });
    }
};
