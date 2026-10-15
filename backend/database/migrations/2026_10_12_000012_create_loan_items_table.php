<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item loan (Database Plan B.3). Satu baris selalu berarti satu unit aset (OD-05);
 * tidak ada kolom quantity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            // Aset yang pernah dipinjam tidak boleh dihapus permanen.
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['loan_id', 'asset_id']);
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_items');
    }
};
