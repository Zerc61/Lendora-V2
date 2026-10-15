<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kebijakan per organisasi (Database Plan B.1, Business Rules C.1). Satu baris
 * per organisasi. Bila baris belum ada, aplikasi memakai nilai default tanpa
 * menulis baris (lihat OrganizationPolicy::resolve).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_policies', function (Blueprint $table) {
            $table->foreignId('organization_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('max_loan_duration_days')->default(14);
            $table->unsignedSmallInteger('min_loan_duration_hours')->default(4);
            $table->unsignedSmallInteger('max_advance_days')->default(60);
            $table->unsignedSmallInteger('min_notice_hours')->default(4);
            $table->unsignedSmallInteger('pickup_window_hours')->default(48);
            $table->unsignedSmallInteger('max_active_loans')->default(3);
            $table->boolean('require_approval')->default(true);
            $table->boolean('require_pre_checkout_inspection')->default(true);
            $table->boolean('require_post_return_inspection')->default(true);
            $table->boolean('allow_renewal')->default(false);
            $table->boolean('allow_borrower_cancel')->default(true);
            $table->unsignedSmallInteger('maintenance_notice_days')->default(7);
            $table->timestamps();
        });

        // Batas nilai dari Business Rules C.1. Nilai di luar rentang ditolak database.
        DB::statement(
            'ALTER TABLE organization_policies ADD CONSTRAINT organization_policies_bounds_check
             CHECK (
                 max_loan_duration_days BETWEEN 1 AND 90
                 AND min_loan_duration_hours BETWEEN 1 AND 72
                 AND max_advance_days BETWEEN 1 AND 365
                 AND min_notice_hours BETWEEN 0 AND 168
                 AND pickup_window_hours BETWEEN 1 AND 336
                 AND max_active_loans BETWEEN 1 AND 50
                 AND maintenance_notice_days BETWEEN 1 AND 90
             )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_policies');
    }
};
