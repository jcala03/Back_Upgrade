<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('commission_enabled')
                ->default(false)
                ->after('target_profit_percent');
            $table->unsignedBigInteger('commission_amount')
                ->nullable()
                ->after('commission_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'commission_enabled',
                'commission_amount',
            ]);
        });
    }
};
