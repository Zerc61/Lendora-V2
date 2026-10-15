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
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('password');
            $table->string('phone', 32)->nullable()->after('status');
            $table->string('avatar_path')->nullable()->after('phone');
            $table->timestamp('last_login_at')->nullable()->after('avatar_path');
            $table->softDeletes();
        });

        // `pending` untuk pendaftar yang menunggu aktivasi admin (OD-29).
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('pending', 'active', 'inactive'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['status', 'phone', 'avatar_path', 'last_login_at']);
        });
    }
};
