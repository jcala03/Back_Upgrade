<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_category_fields', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_category_id')
                ->constrained('product_categories')
                ->cascadeOnDelete();

            $table->string('name');
            $table->string('field_key');
            $table->string('type', 30)->default('text');

            $table->json('options')->nullable();

            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(1);

            $table->timestamps();

            $table->unique(['product_category_id', 'field_key']);
            $table->index('type');
            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_category_fields');
    }
};
