<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('charges_total')->default(0);
            $table->char('currency', 3)->default('COP');
            $table->string('fulfillment_type', 20)->default('pickup')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['fulfillment_type']);
            $table->dropColumn(['charges_total', 'currency', 'fulfillment_type']);
        });
    }
};
