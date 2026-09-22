<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table
                ->string('compatibility_type', 32)
                ->nullable()
                ->after('product_brand_id');

            $table->index(
                'compatibility_type',
                'products_compatibility_type_index'
            );
        });

        DB::table('products')
            ->whereIn('id', function ($query) {
                $query
                    ->select('product_id')
                    ->from('product_vehicle_compatibilities')
                    ->distinct();
            })
            ->update([
                'compatibility_type' => 'vehicle_specific',
            ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_compatibility_type_index');
            $table->dropColumn('compatibility_type');
        });
    }
};
