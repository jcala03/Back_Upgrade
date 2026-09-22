<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_name')->nullable()->change();
            $table->string('customer_email')->nullable()->change();
            $table->string('customer_phone')->nullable()->change();

            $table->string('customer_document')->nullable()->after('customer_phone');
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();

            $table->string('origin')->default('ecommerce')->after('order_number')->index();
            $table->unsignedBigInteger('discount_total')->default(0)->after('subtotal');

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

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('stock_committed_at')->nullable();
            $table->timestamp('stock_reverted_at')->nullable();
            $table->text('cancel_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_version_id');
            $table->dropConstrainedForeignId('vehicle_model_id');
            $table->dropConstrainedForeignId('vehicle_brand_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn([
                'origin', 'customer_document', 'discount_total', 'vehicle_year',
                'vehicle_plate', 'vehicle_vin', 'vehicle_color', 'vehicle_notes',
                'vehicle_brand_name', 'vehicle_model_name', 'vehicle_version_name',
                'confirmed_at', 'completed_at', 'cancelled_at',
                'stock_committed_at', 'stock_reverted_at', 'cancel_reason',
            ]);
            $table->string('customer_name')->nullable(false)->change();
            $table->string('customer_email')->nullable(false)->change();
            $table->string('customer_phone')->nullable(false)->change();
        });
    }
};
