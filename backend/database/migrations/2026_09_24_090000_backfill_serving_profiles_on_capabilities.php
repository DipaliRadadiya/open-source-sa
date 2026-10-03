<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Record what each existing server will actually host.
 *
 * `capabilities.php` answers "is PHP installed", which is true on every box —
 * the panel is a Laravel application. The setup page and the site-type catalog
 * were reading it as "may this box host PHP sites", which was the same question
 * until the docker stack existed. It is not the same question any more: a
 * Docker box has PHP for the panel and hosts containers only, and the two
 * answers have to be recorded separately.
 *
 * Rows written before that distinction have no `serving_profiles`, so they are
 * backfilled from the stack that built them. A row with no stack — a server
 * migrated in from another panel — is left alone: it is certainly hosting
 * something, and guessing "nothing" would empty a working server's catalogue.
 * `ServerCapabilities::servingProfiles()` treats an absent value as the
 * permissive default for exactly that case.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const BY_STACK = [
        'lemp' => ['php', 'static'],
        'lamp' => ['php', 'static'],
        'ols' => ['php', 'static'],
        'mern' => ['node', 'static'],
        'docker' => ['docker'],
    ];

    public function up(): void
    {
        foreach (DB::table('server_capabilities')->get() as $row) {
            $capabilities = json_decode((string) $row->capabilities, true);

            if (! is_array($capabilities) || isset($capabilities['serving_profiles'])) {
                continue;
            }

            $profiles = self::BY_STACK[$row->stack] ?? null;

            if ($profiles === null) {
                continue;
            }

            $capabilities['serving_profiles'] = $profiles;

            DB::table('server_capabilities')
                ->where('id', $row->id)
                ->update(['capabilities' => json_encode($capabilities)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('server_capabilities')->get() as $row) {
            $capabilities = json_decode((string) $row->capabilities, true);

            if (! is_array($capabilities) || ! isset($capabilities['serving_profiles'])) {
                continue;
            }

            unset($capabilities['serving_profiles']);

            DB::table('server_capabilities')
                ->where('id', $row->id)
                ->update(['capabilities' => json_encode($capabilities)]);
        }
    }
};
