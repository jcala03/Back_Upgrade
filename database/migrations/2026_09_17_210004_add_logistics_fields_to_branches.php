<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedInteger('ecommerce_priority')->default(100)->index();
            $table->char('country_code', 2)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('address_line1', 220)->nullable();
            $table->string('address_line2', 220)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropIndex(['ecommerce_priority']);
            $table->dropColumn([
                'ecommerce_priority', 'country_code', 'state', 'postal_code',
                'address_line1', 'address_line2',
            ]);
        });
    }
};
