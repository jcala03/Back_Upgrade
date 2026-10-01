<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Do not hide products or manufacture placeholder paths during a deployment.
        $invalid = DB::table('products')->where('is_visible', true)
            ->where(fn ($q) => $q->whereNull('main_image')->orWhereRaw("TRIM(main_image) = ''"))->pluck('id');
        if ($invalid->isNotEmpty()) {
            throw new RuntimeException('Published products require image repair before migration: '.$invalid->implode(', '));
        }
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path', 2048);
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            // MySQL/MariaDB allow multiple NULLs, but never two primary rows per product.
            $table->unsignedBigInteger('primary_product_id')->storedAs('CASE WHEN is_primary THEN product_id ELSE NULL END')->unique();
            $table->timestamps();
            $table->index(['product_id', 'sort_order']);
        });
        $this->backfillLegacyImages();
    }

    public function backfillLegacyImages(): void
    {
        DB::table('products')->whereNotNull('main_image')->whereRaw("TRIM(main_image) != ''")
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('product_images')->whereColumn('product_images.product_id', 'products.id'))
            ->orderBy('id')->chunkById(200, function ($products) {
                foreach ($products as $product) {
                    DB::table('product_images')->insert([
                        'product_id' => $product->id, 'path' => $product->main_image,
                        'is_primary' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // The legacy primary path remains usable; storage files are never deleted by rollback.
        Schema::dropIfExists('product_images');
    }
};
