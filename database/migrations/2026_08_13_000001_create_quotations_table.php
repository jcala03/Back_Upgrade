<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_number')->unique();
            $table->string('status')->default('draft')->index();
            $table->date('valid_until')->nullable()->index();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_document', 80)->nullable();
            $table->string('customer_city', 120)->nullable();
            $table->string('customer_address', 220)->nullable();
            $table->text('customer_notes')->nullable();
            $table->foreignId('vehicle_brand_id')->nullable()->constrained('vehicle_brands')->nullOnDelete();
            $table->foreignId('vehicle_model_id')->nullable()->constrained('vehicle_models')->nullOnDelete();
            $table->foreignId('vehicle_version_id')->nullable()->constrained('vehicle_versions')->nullOnDelete();
            $table->unsignedSmallInteger('vehicle_year')->nullable();
            $table->string('vehicle_plate', 30)->nullable();
            $table->string('vehicle_vin', 80)->nullable();
            $table->string('vehicle_color', 80)->nullable();
            $table->text('vehicle_notes')->nullable();
            $table->string('vehicle_brand_name')->nullable();
            $table->string('vehicle_model_name')->nullable();
            $table->string('vehicle_version_name')->nullable();
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('total');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
