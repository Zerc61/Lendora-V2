<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran booking (rencana Bagian 6.4, keputusan 3, 4, 6, 7).
 *
 * Sumbu pembayaran terpisah dari status loan: loan tetap `approved` saat
 * dibayar, karena menambah status loan berarti mengubah state machine. Retry
 * boleh banyak baris per loan dengan `order_id` Midtrans masing-masing; hanya
 * satu yang boleh `paid`, dijaga indeks unik parsial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->string('order_id')->unique();
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamps();

            $table->index(['loan_id', 'status']);
        });

        DB::statement(
            "ALTER TABLE payments ADD CONSTRAINT payments_status_check
             CHECK (status IN ('pending', 'paid', 'expired', 'failed'))"
        );

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_amount_check
             CHECK (amount >= 0)'
        );

        DB::statement(
            "CREATE UNIQUE INDEX payments_one_paid_per_loan
             ON payments (loan_id) WHERE status = 'paid'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_one_paid_per_loan');

        Schema::dropIfExists('payments');
    }
};
