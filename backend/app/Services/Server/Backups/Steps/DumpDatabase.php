<?php

namespace App\Services\Server\Backups\Steps;

use App\Contracts\BackupStep;
use App\Models\Database;
use App\Services\Server\Backups\BackupContext;
use App\Services\Server\Databases\DatabaseManager;
use RuntimeException;

/**
 * Dumps the application's databases.
 *
 * Reuses DatabaseEngine::dump(), which already does what the MariaDB
 * operations guide prescribes — `--single-transaction` for a consistent
 * snapshot without locking writes, `--quick` to stream rather than buffer
 * whole tables, credentials in a 0600 defaults-file and never on argv.
 * A second dump implementation here would be a second place for those
 * decisions to be got wrong.
 */
class DumpDatabase implements BackupStep
{
    public function __construct(private DatabaseManager $databases) {}

    public function key(): string
    {
        return 'dump_database';
    }

    public function appliesTo(BackupContext $context): bool
    {
        return $context->wantsDatabase();
    }

    public function run(BackupContext $context): void
    {
        $excluded = (array) ($context->target->database_excludes ?? []);

        $databases = Database::query()
            ->where('application_id', $context->application()->id)
            ->get()
            ->reject(fn (Database $database): bool => in_array($database->name, $excluded, true));

        if ($databases->isEmpty()) {
            // Not an error for a files-only site that happens to be typed
            // `full`, but it must be visible: a "database backup" holding no
            // database is the kind of thing discovered during a restore.
            $context->manifest['databases'] = [];

            return;
        }

        $dumped = [];

        foreach ($databases as $database) {
            $path = $context->track(
                $context->workingDirectory.'/db-'.$database->name.'.sql',
            );

            $this->databases->engine($database->engine)->dump($database->name, $path);

            if (! is_file($path) || filesize($path) === 0) {
                // The engine reported success and produced nothing. Treating
                // that as a backup is how people discover empty dumps a year
                // later, at the worst possible moment.
                throw new RuntimeException("database dump for {$database->name} is empty");
            }

            $dumped[] = ['name' => $database->name, 'bytes' => filesize($path)];
        }

        $context->manifest['databases'] = $dumped;
    }

    public function cleanup(BackupContext $context): void
    {
        // Local dumps are removed by the runner via the tracked-artefact list;
        // nothing extra to undo here.
    }
}
