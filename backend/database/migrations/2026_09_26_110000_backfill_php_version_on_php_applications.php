<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Store the PHP version a blank-version PHP site actually runs.
 *
 * A blank `php_version` meant "the server default", resolved again at every
 * render. So the API showed no version for a site that plainly had one, and a
 * change to the default would move every such site to another PHP the next
 * time its config was rendered. New sites store the resolved default at
 * creation; this gives existing ones the same value they already run on, so
 * nothing on the server changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $default = (string) config('server.default_php_version');

        if ($default === '') {
            return;
        }

        DB::table('applications')
            ->where('serving_profile', 'php')
            ->where(fn ($query) => $query->whereNull('php_version')->orWhere('php_version', ''))
            ->update(['php_version' => $default]);
    }

    /**
     * Deliberately leaves the stored versions in place.
     *
     * Once filled, a backfilled row cannot be told apart from one where the
     * user picked the same version, and blanking those would move a user's
     * explicit choice back onto "whatever the default becomes". Keeping the
     * value is also exactly equivalent at the moment of rollback: it is the
     * version the blank one resolved to, and older code reads it the same way.
     */
    public function down(): void
    {
        //
    }
};
