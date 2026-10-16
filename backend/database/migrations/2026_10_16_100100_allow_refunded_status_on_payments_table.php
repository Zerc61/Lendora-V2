<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dana yang dikembalikan Midtrans perlu statusnya sendiri.
 *
 * Tanpa `refunded`, notifikasi `refund`, `partial_refund`, dan `chargeback` jatuh
 * ke `failed` — pembayaran yang benar-benar lunas lalu tampak gagal. Indeks unik
 * parsial `payments_one_paid_per_loan` hanya berlaku untuk status `paid`, jadi
 * pembayaran yang dana dikembalikan otomatis membebaskan slotnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_status_check');

        DB::statement(
            "ALTER TABLE payments ADD CONSTRAINT payments_status_check
             CHECK (status IN ('pending', 'paid', 'expired', 'failed', 'refunded'))"
        );
    }

    public function down(): void
    {
        // Status `refunded` harus punya nilai yang masih diizinkan sebelum
        // constraint lama dipasang kembali.
        DB::statement("UPDATE payments SET status = 'failed' WHERE status = 'refunded'");

        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_status_check');

        DB::statement(
            "ALTER TABLE payments ADD CONSTRAINT payments_status_check
             CHECK (status IN ('pending', 'paid', 'expired', 'failed'))"
        );
    }
};
