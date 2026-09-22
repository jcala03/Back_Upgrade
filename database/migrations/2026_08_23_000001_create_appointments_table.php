<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('responsible_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('source', 30)->default('crm');
            $table->string('status', 30)->default('confirmed');
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('contact_name', 160);
            $table->string('contact_phone', 40);
            $table->string('contact_email', 160)->nullable();
            $table->string('vehicle_description', 255)->nullable();
            $table->string('service_name', 160)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->boolean('availability_override')->default(false);
            $table->string('availability_override_reason')->nullable();
            $table->foreignId('availability_overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('availability_overridden_at')->nullable();
            $table->timestamps();
            $table->index(['responsible_employee_id', 'status', 'starts_at', 'ends_at'], 'appointment_employee_schedule_index');
            $table->index(['customer_id', 'starts_at']);
            $table->index(['customer_vehicle_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
