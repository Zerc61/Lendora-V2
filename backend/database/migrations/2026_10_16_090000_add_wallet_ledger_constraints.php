<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagar buku besar dompet.
 *
 * Tiga jaminan tingkat basis data, sebagai lapis terakhir setelah penguncian di
 * `WalletTransactionService`:
 *
 * 1. `reference_id` unik, supaya satu order Midtrans hanya punya satu baris
 *    (idempotensi tidak bergantung pada kode aplikasi saja).
 * 2. `amount > 0`, karena arah saldo ditentukan kolom `type`, bukan tanda
 *    nominal.
 * 3. `users.wallet_balance >= 0`, supaya saldo tidak pernah negatif walau ada
 *    jalur penulisan yang lupa memeriksa kecukupan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->unique('reference_id');
        });

        DB::statement(
            'ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_amount_check
             CHECK (amount > 0)'
        );

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_wallet_balance_check
             CHECK (wallet_balance >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_wallet_balance_check');
        DB::statement('ALTER TABLE wallet_transactions DROP CONSTRAINT IF EXISTS wallet_transactions_amount_check');

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropUnique(['reference_id']);
        });
    }
};
