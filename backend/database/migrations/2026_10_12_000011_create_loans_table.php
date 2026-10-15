<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregate Loan (Database Plan B.3, Lending Lifecycle). Satu state machine,
 * tujuh state, empat terminal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Loan historis tidak boleh hilang walau borrower dihapus.
            $table->foreignId('borrower_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('requested');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('due_at')->nullable();
            $table->text('purpose')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('checked_out_at')->nullable();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('returned_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_late')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'borrower_id', 'status']);
            $table->index(['status', 'ends_at']);
        });

        // Nilai status dari Business Rules C.1 bagian C.1 dan Database Plan C.5.
        DB::statement(
            "ALTER TABLE loans ADD CONSTRAINT loans_status_check
             CHECK (status IN ('requested', 'approved', 'rejected', 'cancelled', 'expired', 'checked_out', 'returned'))"
        );

        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_period_check CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_due_after_end_check CHECK (due_at IS NULL OR due_at >= ends_at)');
        DB::statement(
            'ALTER TABLE loans ADD CONSTRAINT loans_return_after_checkout_check
             CHECK (returned_at IS NULL OR checked_out_at IS NULL OR returned_at >= checked_out_at)'
        );
        DB::statement(
            'ALTER TABLE loans ADD CONSTRAINT loans_checkout_requires_approval_check
             CHECK (checked_out_at IS NULL OR approved_at IS NOT NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
