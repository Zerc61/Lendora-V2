<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gerbang bayar di muka (rencana 3.3, keputusan 5).
 *
 * Default false: pembayaran murni catatan dan tidak menghalangi serah terima,
 * seperti sebelum Tahap 4. Provider bisa menyalakannya sendiri lewat kebijakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_policies', function (Blueprint $table) {
            $table->boolean('require_prepayment')->default(false)->after('require_approval');
        });
    }

    public function down(): void
    {
        Schema::table('organization_policies', function (Blueprint $table) {
            $table->dropColumn('require_prepayment');
        });
    }
};
