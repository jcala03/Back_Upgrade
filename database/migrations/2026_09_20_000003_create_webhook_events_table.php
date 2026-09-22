<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 120);
            $table->string('event_key');
            $table->string('event_type', 120);
            $table->string('provider_transaction_id')->nullable();
            $table->char('payload_hash', 64);
            $table->string('status', 40)->default('received');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_key'], 'webhook_events_provider_key_unique');
            $table->index(['provider', 'status', 'received_at'], 'webhook_events_processing_idx');
            $table->index('provider_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
