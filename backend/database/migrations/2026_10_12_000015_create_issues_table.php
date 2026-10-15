<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue (Database Plan B.4, Issue & Maintenance B). Pengamatan masalah, terpisah
 * dari pekerjaan perbaikannya (`maintenance_tickets`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // Riwayat kerusakan adalah alat bukti; aset tidak dihapus permanen.
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained('loans')->nullOnDelete();
            $table->string('type', 30);
            $table->string('severity', 20);
            $table->string('status', 20)->default('open');
            $table->text('description');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'severity']);
            $table->index(['asset_id', 'status']);
        });

        // Nilai dari Business Rules C.1 dan Issue & Maintenance B/D.
        DB::statement(
            "ALTER TABLE issues ADD CONSTRAINT issues_type_check
             CHECK (type IN ('damage', 'loss', 'other'))"
        );
        DB::statement(
            "ALTER TABLE issues ADD CONSTRAINT issues_severity_check
             CHECK (severity IN ('low', 'medium', 'high', 'critical'))"
        );
        DB::statement(
            "ALTER TABLE issues ADD CONSTRAINT issues_status_check
             CHECK (status IN ('open', 'reviewing', 'resolved', 'dismissed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
