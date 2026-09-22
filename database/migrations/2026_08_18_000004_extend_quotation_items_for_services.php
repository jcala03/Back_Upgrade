<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->string('item_type', 20)->default('product')->after('quotation_id')->index();
            $table->foreignId('service_id')->nullable()->after('product_variant_id')->constrained()->nullOnDelete();
            $table->string('service_name', 160)->nullable()->after('variant_specs');
            $table->text('service_description')->nullable()->after('service_name');
            $table->string('product_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::statement('UPDATE quotation_items SET product_name = COALESCE(product_name, service_name, \'Item histórico\') WHERE product_name IS NULL');
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
            $table->dropIndex(['item_type']);
            $table->dropColumn(['item_type', 'service_name', 'service_description']);
            $table->string('product_name')->nullable(false)->change();
        });
    }
};
