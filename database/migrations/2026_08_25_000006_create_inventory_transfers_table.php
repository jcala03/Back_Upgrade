<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('source_branch_id')
                ->constrained('branches')
                ->restrictOnDelete();
            $table->foreignId('destination_branch_id')
                ->constrained('branches')
                ->restrictOnDelete();
            $table->string('status', 32)->default('requested');
            $table->foreignId('requested_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('dispatched_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('received_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            // DATETIME avoids MariaDB 10.4's implicit ON UPDATE behavior for
            // the first non-null TIMESTAMP when explicit defaults are disabled.
            $table->dateTime('requested_at');
            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('client_request_key', 191)->nullable()->unique();
            $table->timestamps();

            $table->index(
                ['status', 'requested_at'],
                'inventory_transfers_status_requested_idx'
            );
            $table->index(
                ['source_branch_id', 'requested_at'],
                'inventory_transfers_source_requested_idx'
            );
            $table->index(
                ['destination_branch_id', 'requested_at'],
                'inventory_transfers_destination_requested_idx'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE inventory_transfers
            ADD CONSTRAINT inventory_transfers_distinct_branches_chk
            CHECK (source_branch_id <> destination_branch_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE inventory_transfers
            ADD CONSTRAINT inventory_transfers_status_chk
            CHECK (status IN ('requested', 'in_transit', 'received', 'cancelled'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};
