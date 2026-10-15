<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identitas lengkap borrower (OD-36): kontak, domisili, status pendidikan,
     * dan penanda tambahan agar admin bisa mengenali user saat diperlukan.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('student_id', 24)->nullable()->after('phone');
            $table->string('city', 80)->nullable()->after('student_id');
            $table->string('education_status', 16)->nullable()->after('city');
            $table->text('address')->nullable()->after('education_status');
            $table->string('birth_place', 80)->nullable()->after('address');
            $table->date('birth_date')->nullable()->after('birth_place');
            $table->string('emergency_contact_name', 100)->nullable()->after('birth_date');
            $table->string('emergency_contact_phone', 32)->nullable()->after('emergency_contact_name');
        });

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_education_status_check '.
            "CHECK (education_status IS NULL OR education_status IN ('siswa_smp', 'siswa_smk', 'siswa_sma', 'mahasiswa', 'umum'))"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_education_status_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'student_id',
                'city',
                'education_status',
                'address',
                'birth_place',
                'birth_date',
                'emergency_contact_name',
                'emergency_contact_phone',
            ]);
        });
    }
};
