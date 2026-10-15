<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['action', 'created_at']);
        });

        // Append-only ditegakkan database, bukan hanya konvensi aplikasi.
        // Audit Strategy: audit tidak pernah diubah atau dihapus.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_events_prevent_modification()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only (no UPDATE or DELETE allowed)';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_events_no_update
                BEFORE UPDATE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_prevent_modification();

            CREATE TRIGGER audit_events_no_delete
                BEFORE DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_prevent_modification();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS audit_events_prevent_modification() CASCADE');
        Schema::dropIfExists('audit_events');
    }
};
