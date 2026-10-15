<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lapis 2 availability (OD-15, OD-25, Database Plan C.2 Opsi B). Tabel terpisah
 * memuat satu baris per (loan, asset) selama loan memblokir periode. Baris ada
 * hanya saat status loan `approved` atau `checked_out`.
 *
 * Exclusion constraint mencegah dua baris dengan `asset_id` sama dan range waktu
 * yang overlap. Berlaku untuk semua aset, termasuk loan dengan banyak aset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('status', 30);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestamps();

            $table->unique(['loan_id', 'asset_id']);
            $table->index(['asset_id', 'starts_at']);
        });

        DB::statement(
            "ALTER TABLE loan_reservations ADD CONSTRAINT loan_reservations_status_check
             CHECK (status IN ('approved', 'checked_out'))"
        );
        DB::statement(
            'ALTER TABLE loan_reservations ADD CONSTRAINT loan_reservations_period_check
             CHECK (ends_at > starts_at)'
        );

        // Butuh extension btree_gist (migration 000009). Range setengah terbuka [).
        DB::statement(
            "ALTER TABLE loan_reservations ADD CONSTRAINT loan_reservations_no_overlap
             EXCLUDE USING gist (
                 asset_id WITH =,
                 tstzrange(starts_at, ends_at) WITH &&
             ) WHERE (status IN ('approved', 'checked_out'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_reservations');
    }
};
