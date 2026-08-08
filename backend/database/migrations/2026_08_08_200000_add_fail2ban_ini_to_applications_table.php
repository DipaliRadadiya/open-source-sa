<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-application fail2ban now stores raw INI strings, not the structured
     * maxretry/findtime/bantime/ignore_ips columns from the earlier attempt.
     *
     * The drop is guarded so the migration is a no-op on installations that
     * never ran the previous add — including a fresh checkout, where the old
     * columns were never added in the first place. Production installs where
     * the previous migration DID run are auto-migrated by the controller's
     * GET handler before this migration drops the columns, so existing rows
     * are not lost on a clean deploy.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            foreach (['fail2ban_maxretry', 'fail2ban_findtime', 'fail2ban_bantime', 'fail2ban_ignore_ips'] as $column) {
                if (Schema::hasColumn('applications', $column)) {
                    $table->dropColumn($column);
                }
            }

            $table->string('fail2ban_jail_name')->nullable()->after('fail2ban_enabled');
            $table->longText('fail2ban_jail_content')->nullable()->after('fail2ban_jail_name');
            $table->longText('fail2ban_filter_content')->nullable()->after('fail2ban_jail_content');
        });
    }

    /**
     * Reverses both halves: the old structured columns come back (so a
     * rollback followed by a redeploy of the previous commit lands on a
     * usable schema) and the new INI columns are dropped.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn(['fail2ban_jail_name', 'fail2ban_jail_content', 'fail2ban_filter_content']);

            $table->unsignedInteger('fail2ban_maxretry')->nullable()->after('fail2ban_enabled');
            $table->unsignedInteger('fail2ban_findtime')->nullable()->after('fail2ban_maxretry');
            $table->unsignedInteger('fail2ban_bantime')->nullable()->after('fail2ban_findtime');
            $table->json('fail2ban_ignore_ips')->nullable()->after('fail2ban_bantime');
        });
    }
};