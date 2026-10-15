<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MaintenanceLog (Database Plan B.4, Issue & Maintenance G). Append-only:
 * setiap perubahan state ticket menambah satu baris, tidak pernah menimpa (M-7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('maintenance_tickets')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->text('note')->nullable();
            // OD-19: biaya tidak masuk MVP, tetapi kolom disiapkan sejak awal.
            $table->decimal('cost', 12, 2)->nullable();
            // Log bersifat catatan tambahan, tanpa updated_at (Database Plan B.4).
            $table->timestamp('created_at')->useCurrent();

            $table->index('ticket_id');
        });

        // Append-only ditegakkan database, bukan hanya konvensi aplikasi (M-7).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION maintenance_logs_prevent_modification()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'maintenance_logs is append-only (no UPDATE or DELETE allowed)';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER maintenance_logs_no_update
                BEFORE UPDATE ON maintenance_logs
                FOR EACH ROW EXECUTE FUNCTION maintenance_logs_prevent_modification();

            CREATE TRIGGER maintenance_logs_no_delete
                BEFORE DELETE ON maintenance_logs
                FOR EACH ROW EXECUTE FUNCTION maintenance_logs_prevent_modification();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS maintenance_logs_prevent_modification() CASCADE');
        Schema::dropIfExists('maintenance_logs');
    }
};
