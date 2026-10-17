<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alur serah terima yang wajib lewat pagar bayar (rombak 2026-10-17).
 *
 * Alur menjadi: pengajuan → persetujuan operator → bayar di muka → serah
 * terima. Dua perubahan di sini membuat alur itu bawaan, bukan pilihan
 * per penyedia yang mudah terlewat:
 *
 * 1. Kolom `require_prepayment` berubah default-nya menjadi true, semua
 *    kebijakan yang sudah ada ikut dinyalakan. Penyedia yang memang tidak
 *    mau menagih tetap bisa mematikannya lewat PATCH kebijakan.
 * 2. (default sebelumnya false: checkout tetap jalan tanpa pembayaran.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_policies', function (Blueprint $table) {
            $table->boolean('require_prepayment')->default(true)->change();
        });

        DB::table('organization_policies')
            ->where('require_prepayment', false)
            ->update(['require_prepayment' => true]);
    }

    public function down(): void
    {
        Schema::table('organization_policies', function (Blueprint $table) {
            $table->boolean('require_prepayment')->default(false)->change();
        });
    }
};
