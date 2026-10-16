<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Masa berlaku top up dompet.
 *
 * `payments` sudah punya kolom ini sejak awal; buku besar dompet belum, sehingga
 * rekonsiliasi memakai jendela waktu sejak `created_at` sebagai pengganti. Dengan
 * kolom ini masa berlaku ikut tercatat, sesuai lama halaman pembayaran Midtrans
 * (`expiry` yang dikirim saat charge).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
