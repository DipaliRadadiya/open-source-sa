<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the certificate the web server is *presenting* expires.
 *
 * Separate from `expires_at`, which is read off the file on disk, because the
 * two can disagree and the disagreement is the failure worth showing. Renewal
 * replaces the file; the web server keeps serving what it loaded at startup
 * until something reloads it. A panel reading only the file reports eighty-nine
 * days remaining while visitors get an expired certificate.
 *
 * Null when the question could not be answered — nothing listening on 443, TLS
 * refused, no certificate for that name. Null is not "expired": a server that
 * did not answer has told us nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->timestamp('served_expires_at')->nullable()->after('expires_at');
            $table->timestamp('served_checked_at')->nullable()->after('served_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['served_expires_at', 'served_checked_at']);
        });
    }
};
