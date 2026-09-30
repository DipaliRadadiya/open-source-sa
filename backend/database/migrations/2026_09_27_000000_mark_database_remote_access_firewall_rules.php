<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the firewall rules opened for remote database users their own origin.
 *
 * They were written as `default`, the origin of the panel's own SSH/HTTP
 * rules, so they could not be removed while the firewall was on and were
 * never closed when the user went away. New ones are written as `db_user`;
 * this moves the ones already on a server over, so they can be removed too.
 *
 * Matched on what DatabaseFirewall wrote and nothing else: one of the engine
 * ports, an `allow` on tcp with no range, and its exact description
 * ("MARIADB remote access"). The panel's own seeded rules carry no
 * description, and an administrator's rule is `user` already.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rules('default')->update(['origin' => 'db_user']);
    }

    /**
     * Back to `default`, which is how older code wrote these and how it
     * reads them. Also catches ones opened as `db_user` after the upgrade —
     * older code has no other origin to give them.
     */
    public function down(): void
    {
        $this->rules('db_user')->update(['origin' => 'default']);
    }

    private function rules(string $origin)
    {
        $engines = (array) config('server.databases.engines', []);

        $ports = array_values(array_unique(array_map(
            fn (array $engine) => (int) ($engine['default_port'] ?? 0),
            $engines,
        )));

        $descriptions = array_map(
            fn (string $engine) => strtoupper($engine).' remote access',
            array_keys($engines),
        );

        return DB::table('firewall_rules')
            ->where('origin', $origin)
            ->whereIn('port_from', $ports)
            ->whereNull('port_to')
            ->where('protocol', 'tcp')
            ->where('action', 'allow')
            ->whereIn('description', $descriptions);
    }
};
