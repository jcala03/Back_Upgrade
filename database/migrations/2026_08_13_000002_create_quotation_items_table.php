<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('product_slug')->nullable();
            $table->string('product_sku')->nullable();
            $table->string('variant_name')->nullable();
            $table->string('variant_sku')->nullable();
            $table->json('variant_specs')->nullable();
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('unit_cost')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('total');
            $table->timestamps();
            $table->index(['quotation_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
    }
};
