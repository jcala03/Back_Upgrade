<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('business_name', 160);
            $table->string('legal_name', 200)->nullable();
            $table->string('tax_id', 80)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('address', 220)->nullable();
            $table->string('city', 120)->nullable();
            $table->unsignedSmallInteger('quotation_validity_days')->default(15);
            $table->string('order_notification_email', 160)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_settings');
    }
};
