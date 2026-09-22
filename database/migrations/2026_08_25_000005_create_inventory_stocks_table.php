<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();
            $table->foreignId('inventory_item_id')
                ->constrained('inventory_items')
                ->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('minimum_quantity')->default(0);
            $table->timestamps();

            $table->unique(
                ['branch_id', 'inventory_item_id'],
                'inventory_stocks_branch_item_unique'
            );
            $table->index(
                ['branch_id', 'quantity'],
                'inventory_stocks_branch_quantity_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stocks');
    }
};
