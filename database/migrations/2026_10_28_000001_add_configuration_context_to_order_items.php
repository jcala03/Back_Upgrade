<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->json('configuration_context')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('order_items')->whereNotNull('configuration_context')->exists()) {
            throw new RuntimeException('Cannot remove historical configuration snapshots. Disable purchases instead.');
        }
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('configuration_context'));
    }
};
