<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('capability', 120);
            $table->foreignId('granted_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'capability'],
                'user_capabilities_user_capability_unique'
            );
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->foreign('granted_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_capabilities');
    }
};
