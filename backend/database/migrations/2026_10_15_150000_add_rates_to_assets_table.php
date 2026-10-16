<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tarif sewa pada aset fisik (keputusan 8, rencana Bagian 6.1).
 *
 * Satu baris `assets` adalah satu unit fisik, jadi tarif menempel di unit. Batas
 * nyatanya: 20 unit identik berarti 20 baris saat harga berubah. Jalur
 * peningkatannya model produk (`equipment_models`, P1), bukan tabel sekarang.
 *
 * Tarif dan deposit nullable karena aset lama belum punya harga. Booking menolak
 * aset tanpa `daily_rate`, jadi tidak ada penyewaan gratis karena kolom kosong.
 * `deposit_amount` disiapkan untuk P1 (keputusan 10): kolomnya ada, tetapi
 * penagihan deposit belum dijalankan di Fase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->decimal('daily_rate', 15, 2)->nullable()->after('purchase_price');
            $table->unsignedSmallInteger('min_days')->nullable()->after('daily_rate');
            $table->decimal('deposit_amount', 15, 2)->nullable()->after('min_days');
        });

        DB::statement(
            'ALTER TABLE assets ADD CONSTRAINT assets_daily_rate_check
             CHECK (daily_rate IS NULL OR daily_rate >= 0)'
        );

        DB::statement(
            'ALTER TABLE assets ADD CONSTRAINT assets_min_days_check
             CHECK (min_days IS NULL OR min_days >= 1)'
        );

        DB::statement(
            'ALTER TABLE assets ADD CONSTRAINT assets_deposit_amount_check
             CHECK (deposit_amount IS NULL OR deposit_amount >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE assets DROP CONSTRAINT IF EXISTS assets_daily_rate_check');
        DB::statement('ALTER TABLE assets DROP CONSTRAINT IF EXISTS assets_min_days_check');
        DB::statement('ALTER TABLE assets DROP CONSTRAINT IF EXISTS assets_deposit_amount_check');

        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['daily_rate', 'min_days', 'deposit_amount']);
        });
    }
};
