<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifikasi in-app bawaan Laravel (Database notifications).
 *
 * Notifikasi hidup per pengguna lintas organisasi — pemiliknya adalah user yang
 * login, bukan tenant — sehingga tabel ini tidak memakai global scope organisasi.
 * `data` menyimpan payload JSON yang dibentuk dispatcher (judul, isi, aksi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
