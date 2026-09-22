<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->nullable()
                ->unique()
                ->constrained('products')
                ->restrictOnDelete();
            $table->foreignId('product_variant_id')
                ->nullable()
                ->unique()
                ->constrained('product_variants')
                ->restrictOnDelete();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE inventory_items
            ADD CONSTRAINT inventory_items_exactly_one_catalog_reference_chk
            CHECK (
                (product_id IS NOT NULL AND product_variant_id IS NULL)
                OR
                (product_id IS NULL AND product_variant_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
