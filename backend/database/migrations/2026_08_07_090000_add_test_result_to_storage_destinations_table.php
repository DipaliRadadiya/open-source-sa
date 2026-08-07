<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persist the outcome of the connection probe.
     *
     * Without this the Test button's verdict lives only in the browser tab
     * that pressed it: navigate away and the panel can no longer tell a
     * destination it has never spoken to from one it has confirmed works —
     * which is exactly the question someone setting up backups is asking.
     *
     * `last_test_success` is nullable on purpose: null means never tested,
     * and that is a different answer from "tested and failed".
     */
    public function up(): void
    {
        Schema::table('storage_destinations', function (Blueprint $table): void {
            $table->timestamp('last_tested_at')->nullable()->after('secret_key');
            $table->boolean('last_test_success')->nullable()->after('last_tested_at');
            // The prober's stable category key ('invalid_credentials' |
            // 'unreachable'), never the raw SDK message — that text can carry
            // a partial access key and a bucket URL.
            $table->string('last_test_error', 32)->nullable()->after('last_test_success');
        });
    }

    public function down(): void
    {
        Schema::table('storage_destinations', function (Blueprint $table): void {
            $table->dropColumn(['last_tested_at', 'last_test_success', 'last_test_error']);
        });
    }
};
