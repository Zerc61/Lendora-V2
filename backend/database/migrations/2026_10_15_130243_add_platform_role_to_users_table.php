<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Peran tingkat platform. Terpisah dari `memberships` karena peran di sana selalu
 * terikat satu organisasi, sedangkan admin platform bekerja lintas organisasi.
 *
 * Ini kolom otorisasi, bukan kredensial: login, session, dan kata sandi tidak
 * tersentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('platform_role', 20)->nullable()->after('status');
        });

        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_platform_role_check
             CHECK (platform_role IS NULL OR platform_role IN ('admin'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_platform_role_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('platform_role');
        });
    }
};
