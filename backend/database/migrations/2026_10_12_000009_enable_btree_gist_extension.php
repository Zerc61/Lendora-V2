<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Exclusion constraint availability (OD-15, OD-25) memakai tipe range pada kolom
 * `asset_id`, jadi butuh extension btree_gist. Membuat extension butuh hak
 * superuser atau kepemilikan database; pada database test, `lendora_user` adalah
 * pemilik sehingga migration ini berjalan. Pada database dev yang dimiliki
 * `postgres`, pemilik harus menjalankan perintah ini sekali secara manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
    }

    public function down(): void
    {
        // Extension sengaja tidak dihapus: bisa dipakai objek lain dan menghapusnya
        // berisiko. Rollback tidak membatalkan extension.
    }
};
