<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_spec_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->foreignId('product_category_field_id')
                ->constrained('product_category_fields')
                ->cascadeOnDelete();

            $table->string('value_text')->nullable();
            $table->decimal('value_number', 12, 2)->nullable();
            $table->boolean('value_boolean')->nullable();

            $table->timestamps();

            $table->unique([
                'product_id',
                'product_category_field_id',
            ], 'product_spec_values_unique');

            $table->index('value_text');
            $table->index('value_number');
            $table->index('value_boolean');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_spec_values');
    }
};
