<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_notifications', function (Blueprint $table) {
            $table->id();

            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable();

            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index('type');
            $table->index('read_at');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_notifications');
    }
};
