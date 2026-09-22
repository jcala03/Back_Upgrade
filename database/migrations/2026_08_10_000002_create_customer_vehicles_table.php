<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_brand_id')->nullable()->constrained('vehicle_brands')->nullOnDelete();
            $table->foreignId('vehicle_model_id')->nullable()->constrained('vehicle_models')->nullOnDelete();
            $table->foreignId('vehicle_version_id')->nullable()->constrained('vehicle_versions')->nullOnDelete();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('plate', 30)->nullable()->index();
            $table->string('vin', 80)->nullable()->index();
            $table->string('color', 80)->nullable();
            $table->string('nickname', 120)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_vehicles');
    }
};
