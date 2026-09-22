<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_transfer_id')
                ->constrained('inventory_transfers')
                ->restrictOnDelete();
            $table->foreignId('inventory_item_id')
                ->constrained('inventory_items')
                ->restrictOnDelete();
            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();
            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->nullOnDelete();
            $table->string('product_name_snapshot', 180);
            $table->string('variant_name_snapshot', 180)->nullable();
            $table->string('sku_snapshot', 120)->nullable();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(
                ['inventory_transfer_id', 'inventory_item_id'],
                'inventory_transfer_items_transfer_item_unique'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE inventory_transfer_items
            ADD CONSTRAINT inventory_transfer_items_positive_quantity_chk
            CHECK (quantity > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_items');
    }
};
