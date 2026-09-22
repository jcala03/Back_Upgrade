<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('product_variant_id')
                ->constrained('branches')
                ->restrictOnDelete();
            $table->foreignId('inventory_item_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('inventory_items')
                ->restrictOnDelete();
            $table->foreignId('inventory_transfer_item_id')
                ->nullable()
                ->after('inventory_item_id')
                ->constrained('inventory_transfer_items')
                ->restrictOnDelete();

            $table->index(
                ['branch_id', 'created_at'],
                'inventory_movements_branch_created_idx'
            );
            $table->index(
                ['inventory_item_id', 'created_at'],
                'inventory_movements_item_created_idx'
            );
            $table->index(
                ['inventory_transfer_item_id', 'type'],
                'inventory_movements_transfer_item_type_idx'
            );
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign('inventory_movements_product_id_foreign');
            $table->dropForeign('inventory_movements_product_variant_id_foreign');

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->restrictOnDelete();
            $table->foreign('product_variant_id')
                ->references('id')
                ->on('product_variants')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('inventory_movements_branch_created_idx');
            $table->dropIndex('inventory_movements_item_created_idx');
            $table->dropIndex('inventory_movements_transfer_item_type_idx');

            $table->dropConstrainedForeignId('inventory_transfer_item_id');
            $table->dropConstrainedForeignId('inventory_item_id');
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign('inventory_movements_product_id_foreign');
            $table->dropForeign('inventory_movements_product_variant_id_foreign');

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();
            $table->foreign('product_variant_id')
                ->references('id')
                ->on('product_variants')
                ->nullOnDelete();
        });
    }
};
