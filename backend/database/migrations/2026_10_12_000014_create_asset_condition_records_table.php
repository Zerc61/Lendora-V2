<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Condition record (Database Plan B.4, Condition & Inspection C). Append-only:
 * setiap pemeriksaan menambah baris, tidak pernah menimpa (D-3, C-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_condition_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Riwayat kondisi adalah alat bukti; aset tidak boleh dihapus selama ada record.
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->string('condition', 20);
            $table->string('source', 20);
            $table->text('notes')->nullable();
            $table->jsonb('checklist_result')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('inspected_at');
            $table->foreignId('loan_id')->nullable()->constrained('loans')->nullOnDelete();
            // Record bersifat catatan tambahan, tanpa updated_at (Database Plan B.4).
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'asset_id', 'inspected_at']);
            $table->index('loan_id');
        });

        // Nilai dari Business Rules C.1 dan Condition & Inspection B.
        DB::statement(
            "ALTER TABLE asset_condition_records ADD CONSTRAINT asset_condition_records_condition_check
             CHECK (condition IN ('excellent', 'good', 'fair', 'poor', 'unserviceable'))"
        );
        DB::statement(
            "ALTER TABLE asset_condition_records ADD CONSTRAINT asset_condition_records_source_check
             CHECK (source IN ('checkout', 'checkin', 'standalone', 'maintenance'))"
        );
        // C-6: kondisi poor atau unserviceable selalu punya catatan.
        DB::statement(
            "ALTER TABLE asset_condition_records ADD CONSTRAINT asset_condition_records_notes_check
             CHECK (condition NOT IN ('poor', 'unserviceable') OR notes IS NOT NULL)"
        );

        // Append-only ditegakkan database, bukan hanya konvensi aplikasi (D-3).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION asset_condition_records_prevent_modification()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'asset_condition_records is append-only (no UPDATE or DELETE allowed)';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER asset_condition_records_no_update
                BEFORE UPDATE ON asset_condition_records
                FOR EACH ROW EXECUTE FUNCTION asset_condition_records_prevent_modification();

            CREATE TRIGGER asset_condition_records_no_delete
                BEFORE DELETE ON asset_condition_records
                FOR EACH ROW EXECUTE FUNCTION asset_condition_records_prevent_modification();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS asset_condition_records_prevent_modification() CASCADE');
        Schema::dropIfExists('asset_condition_records');
    }
};
