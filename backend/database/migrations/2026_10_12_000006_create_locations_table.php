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
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('locations');
            $table->string('name', 255);
            $table->unsignedTinyInteger('level')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'parent_id']);
        });

        // Hierarki maksimal empat tingkat. Level diisi aplikasi dari parent_id.
        // Asset Management C.8.
        DB::statement(
            'ALTER TABLE locations ADD CONSTRAINT locations_level_check CHECK (level BETWEEN 1 AND 4)'
        );

        // Nama unik per organisasi pada simpul yang sama. COALESCE dipakai karena
        // PostgreSQL menganggap NULL berbeda sehingga dua akar bernama sama bisa lolos.
        // Hanya simpul hidup yang dihitung (soft delete boleh dipakai ulang).
        DB::statement(
            'CREATE UNIQUE INDEX locations_organization_id_parent_id_name_unique
             ON locations (organization_id, COALESCE(parent_id, 0), name)
             WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
