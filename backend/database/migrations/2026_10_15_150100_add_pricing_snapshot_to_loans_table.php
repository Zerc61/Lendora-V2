<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot harga pada loan (rencana Bagian 6.3).
 *
 * Jumlah tidak pernah dihitung ulang dari tarif saat ini. Tarif provider boleh
 * berubah kapan saja tanpa mengubah tagihan pemesanan yang sudah dibuat.
 * `pricing_snapshot` menyimpan rincian per unit, jadi tagihan tetap bisa
 * dipertanggungjawabkan walau asetnya sudah diubah atau dihapus nanti.
 *
 * `deposit_snapshot` dicatat tetapi belum ditagih (keputusan 10, P1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->unsignedSmallInteger('days')->nullable()->after('purpose');
            $table->decimal('rate_snapshot', 15, 2)->nullable()->after('days');
            $table->decimal('subtotal', 15, 2)->nullable()->after('rate_snapshot');
            $table->decimal('deposit_snapshot', 15, 2)->nullable()->after('subtotal');
            $table->string('currency', 3)->default('IDR')->after('deposit_snapshot');
            $table->jsonb('pricing_snapshot')->nullable()->after('currency');
        });

        DB::statement(
            'ALTER TABLE loans ADD CONSTRAINT loans_subtotal_check
             CHECK (subtotal IS NULL OR subtotal >= 0)'
        );

        DB::statement(
            'ALTER TABLE loans ADD CONSTRAINT loans_rate_snapshot_check
             CHECK (rate_snapshot IS NULL OR rate_snapshot >= 0)'
        );

        DB::statement(
            'ALTER TABLE loans ADD CONSTRAINT loans_deposit_snapshot_check
             CHECK (deposit_snapshot IS NULL OR deposit_snapshot >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_subtotal_check');
        DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_rate_snapshot_check');
        DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_deposit_snapshot_check');

        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn([
                'days',
                'rate_snapshot',
                'subtotal',
                'deposit_snapshot',
                'currency',
                'pricing_snapshot',
            ]);
        });
    }
};
