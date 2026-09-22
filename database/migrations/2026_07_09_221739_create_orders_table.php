<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->string('order_number')->unique();

            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone');
            $table->string('customer_city')->nullable();
            $table->string('customer_address')->nullable();
            $table->text('customer_notes')->nullable();

            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('total');

            $table->string('status')->default('pending');
            $table->string('payment_status')->default('unpaid');

            $table->string('payment_provider')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('payment_transaction_id')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('payment_status');
            $table->index('customer_email');
            $table->index('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
