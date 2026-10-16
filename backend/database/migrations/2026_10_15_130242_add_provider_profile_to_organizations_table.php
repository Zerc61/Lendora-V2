<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profil provider publik. Organizations tetap menjadi akar tenant; kolom baru ini
 * hanya memperkaya identitas yang aman ditampilkan ke pengunjung.
 *
 * `verification_status` menentukan apakah provider boleh tampil di katalog publik.
 * Hanya provider aktif dan terverifikasi yang muncul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('description')->nullable()->after('slug');
            $table->string('contact_email', 255)->nullable()->after('description');
            $table->string('contact_phone', 32)->nullable()->after('contact_email');
            $table->text('address')->nullable()->after('contact_phone');
            $table->string('city', 80)->nullable()->after('address');
            $table->string('verification_status', 20)->default('pending')->after('city');
        });

        // Nilai dijaga database, bukan hanya aplikasi.
        DB::statement(
            "ALTER TABLE organizations ADD CONSTRAINT organizations_verification_status_check
             CHECK (verification_status IN ('pending', 'verified', 'rejected'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_verification_status_check');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'contact_email',
                'contact_phone',
                'address',
                'city',
                'verification_status',
            ]);
        });
    }
};
