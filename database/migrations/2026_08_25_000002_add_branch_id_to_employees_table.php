<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('user_id');
            $table->index(['branch_id', 'is_active']);
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropIndex(['branch_id', 'is_active']);
            $table->dropColumn('branch_id');
        });
    }
};
