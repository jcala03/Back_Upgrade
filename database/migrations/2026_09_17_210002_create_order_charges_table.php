<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->string('label', 160);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('COP');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_charges');
    }
};
