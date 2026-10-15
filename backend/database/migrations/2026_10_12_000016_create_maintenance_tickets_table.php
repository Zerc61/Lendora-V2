<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MaintenanceTicket (Database Plan B.4, Issue & Maintenance C). Pekerjaan
 * perbaikan; boleh lahir dari issue atau berdiri sendiri (`issue_id` nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            // Ticket boleh hidup tanpa issue (Issue & Maintenance A).
            $table->foreignId('issue_id')->nullable()->constrained('issues')->nullOnDelete();
            $table->string('status', 30)->default('open');
            $table->string('priority', 20);
            $table->timestampTz('scheduled_start')->nullable();
            $table->timestampTz('scheduled_end')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description');
            // OD-19: biaya tidak masuk MVP, tetapi kolom disiapkan sejak awal.
            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->decimal('actual_cost', 12, 2)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['asset_id', 'status']);
        });

        // Nilai dari Business Rules C.1 dan Issue & Maintenance C.
        DB::statement(
            "ALTER TABLE maintenance_tickets ADD CONSTRAINT maintenance_tickets_status_check
             CHECK (status IN ('open', 'assigned', 'in_progress', 'awaiting_parts',
                               'awaiting_verification', 'resolved', 'cancelled'))"
        );
        DB::statement(
            "ALTER TABLE maintenance_tickets ADD CONSTRAINT maintenance_tickets_priority_check
             CHECK (priority IN ('low', 'medium', 'high', 'critical'))"
        );
        DB::statement(
            'ALTER TABLE maintenance_tickets ADD CONSTRAINT maintenance_tickets_schedule_check
             CHECK (scheduled_end IS NULL OR scheduled_start IS NULL OR scheduled_end >= scheduled_start)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_tickets');
    }
};
