<?php

use App\Services\PaymentReconciliationResolutionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('payments')->whereNotNull('reconciliation_required_at')
            ->where(function ($query) {
                $query->whereNull('reconciliation_reason')->orWhere('reconciliation_reason', '');
            })->exists()) {
            throw new LogicException('A legacy reconciliation marker has no reason.');
        }

        Schema::create('payment_reconciliation_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignId('webhook_event_id')->nullable()->constrained('webhook_events')->restrictOnDelete();
            $table->foreignId('parent_review_id')->nullable()->constrained('payment_reconciliation_reviews')->restrictOnDelete();
            $table->string('reason', 120);
            $table->string('state', 40);
            $table->string('latest_decision', 80)->nullable();
            $table->char('detection_key', 64)->unique();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('detected_at');
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['state', 'detected_at'], 'payment_reconciliation_reviews_state_detected_idx');
            $table->index(['reason', 'state'], 'payment_reconciliation_reviews_reason_state_idx');
            $table->index(['payment_id', 'state'], 'payment_reconciliation_reviews_payment_state_idx');
            $table->index(['order_id', 'state'], 'payment_reconciliation_reviews_order_state_idx');
        });

        Schema::create('payment_reconciliation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('payment_reconciliation_reviews')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 60);
            $table->string('previous_state', 40)->nullable();
            $table->string('new_state', 40);
            $table->string('decision', 80)->nullable();
            $table->text('justification')->nullable();
            $table->string('evidence_reference')->nullable();
            $table->foreignId('canonical_payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->string('idempotency_key', 191)->collation('utf8mb4_bin');
            $table->char('request_fingerprint', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['review_id', 'idempotency_key'], 'payment_reconciliation_actions_review_key_unique');
            $table->index(['review_id', 'created_at'], 'payment_reconciliation_actions_review_created_idx');
            $table->index(['decision', 'created_at'], 'payment_reconciliation_actions_decision_created_idx');
        });

        app(PaymentReconciliationResolutionService::class)->backfillLegacyMarkers();
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_actions');
        Schema::dropIfExists('payment_reconciliation_reviews');
    }
};
