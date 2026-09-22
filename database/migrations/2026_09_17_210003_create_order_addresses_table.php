<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('shipping');
            $table->string('recipient_name', 160);
            $table->string('recipient_phone', 40);
            $table->char('country_code', 2);
            $table->string('state', 120);
            $table->string('city', 120);
            $table->string('postal_code', 20);
            $table->string('address_line1', 220);
            $table->string('address_line2', 220)->nullable();
            $table->text('delivery_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_addresses');
    }
};
