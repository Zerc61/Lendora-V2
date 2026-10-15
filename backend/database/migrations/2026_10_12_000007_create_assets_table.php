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
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('lifecycle_status', 30)->default('active');
            $table->string('condition', 30)->default('good');
            $table->string('serial_number', 255)->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->date('warranty_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Kode termasuk baris soft-deleted: kode aset tidak boleh dipakai ulang. AS-2.
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'lifecycle_status']);
            $table->index(['organization_id', 'category_id']);
            $table->index(['organization_id', 'location_id']);
            $table->index(['lifecycle_status', 'condition']);
        });

        DB::statement(
            "ALTER TABLE assets ADD CONSTRAINT assets_lifecycle_status_check
             CHECK (lifecycle_status IN ('active', 'awaiting_inspection', 'under_maintenance', 'retired'))"
        );

        DB::statement(
            "ALTER TABLE assets ADD CONSTRAINT assets_condition_check
             CHECK (condition IN ('excellent', 'good', 'fair', 'poor', 'unserviceable'))"
        );

        DB::statement(
            'ALTER TABLE assets ADD CONSTRAINT assets_purchase_price_check
             CHECK (purchase_price IS NULL OR purchase_price >= 0)'
        );

        // Serial unik per organisasi bila diisi. Null berulang tetap diizinkan.
        DB::statement(
            'CREATE UNIQUE INDEX assets_organization_id_serial_number_unique
             ON assets (organization_id, serial_number)
             WHERE serial_number IS NOT NULL'
        );

        // Full-text search native PostgreSQL (OD-26). Kolom generated ikut terjaga
        // database, bukan hanya diisi aplikasi.
        DB::statement(
            "ALTER TABLE assets ADD COLUMN search_vector tsvector
             GENERATED ALWAYS AS (
                 to_tsvector('simple'::regconfig, coalesce(name, '') || ' ' || coalesce(description, ''))
             ) STORED"
        );

        DB::statement('CREATE INDEX assets_search_vector_gin ON assets USING GIN (search_vector)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
