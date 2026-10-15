<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautkan condition record ke ticket yang memicunya (Database Plan B.4).
 * Kolom ditunda di Fase 7 karena `maintenance_tickets` baru dibuat sekarang.
 * Nullable: sebagian besar record lahir dari serah terima, bukan dari perbaikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_condition_records', function (Blueprint $table) {
            $table->foreignId('ticket_id')
                ->nullable()
                ->after('loan_id')
                ->constrained('maintenance_tickets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asset_condition_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_id');
        });
    }
};
