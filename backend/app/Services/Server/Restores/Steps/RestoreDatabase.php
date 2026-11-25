<?php

namespace App\Services\Server\Restores\Steps;

use App\Contracts\RestoreStep;
use App\Models\Database;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Restores\RestoreContext;
use RuntimeException;

/**
 * Puts each database back to what the archive holds.
 *
 * Drop, recreate, import: importing over the top would keep tables created after the backup.
 * Grants live on the user, so dropping the database does not cost the application its credentials.
 */
class RestoreDatabase implements RestoreStep
{
    public function __construct(private DatabaseManager $databases) {}

    public function key(): string
    {
        return 'restore_database';
    }

    public function appliesTo(RestoreContext $context): bool
    {
        return $context->wantsDatabase();
    }

    public function run(RestoreContext $context): void
    {
        $staging = $context->stagingDirectory;

        if ($staging === null) {
            throw new RuntimeException('the archive was not extracted');
        }

        $dumps = glob($staging.'/db-*.sql') ?: [];

        if ($dumps === []) {
            // Without this the restore would report success having done nothing.
            throw new RuntimeException('this backup contains no database dump');
        }

        // Check everything before anything is dropped, so a restore never half happens.
        $plan = [];

        if ($context->isDatabaseRestore()) {
            $plan[] = [$this->ownDatabase($context, $dumps), $dumps[0]];
        }

        foreach ($context->isDatabaseRestore() ? [] : $dumps as $dump) {
            $name = substr(basename($dump), strlen('db-'), -strlen('.sql'));

            // Dropping a live database to import an empty dump would leave the site with no data
            // (`DumpDatabase` refuses to write one; this covers an archive truncated in transit).
            if (! is_file($dump) || filesize($dump) === 0) {
                throw new RuntimeException("the dump for {$name} is empty");
            }

            $database = Database::query()
                ->where('application_id', $context->application->id)
                ->where('name', $name)
                ->first();

            if ($database !== null
                && $this->databases->driver($database->engine) === 'sql'
                && ! $this->looksComplete($dump)) {
                throw new RuntimeException("the dump for {$name} is incomplete");
            }

            if ($database === null) {
                // Recreating an untracked database would leave an orphan with no user and no owner.
                throw new RuntimeException("the database {$name} is no longer attached to this application");
            }

            $plan[] = [$database, $dump];
        }

        foreach ($plan as [$database, $dump]) {
            $engine = $this->databases->engine($database->engine);

            $engine->dropDatabase($database->name);
            $engine->createDatabase($database->name, $database->charset, $database->collation);
            // Before the load, which may run as them: on PostgreSQL no site user could connect.
            $engine->reattachUsers($database->name, $database->users()->pluck('username')->all());
            $engine->restore($database->name, $dump);
        }
    }

    /**
     * A database backup holds one dump; it goes into the database chosen for this restore, whose
     * name may differ from the dump's. Checked before anything is dropped.
     *
     * @param  list<string>  $dumps
     */
    private function ownDatabase(RestoreContext $context, array $dumps): Database
    {
        $database = $context->database;

        if ($database === null) {
            throw new RuntimeException('the database to restore into no longer exists');
        }

        if (count($dumps) !== 1) {
            throw new RuntimeException('a database backup must hold exactly one dump');
        }

        if (! is_file($dumps[0]) || filesize($dumps[0]) === 0) {
            throw new RuntimeException("the dump for {$database->name} is empty");
        }

        if ($this->databases->driver($database->engine) === 'sql' && ! $this->looksComplete($dumps[0])) {
            throw new RuntimeException("the dump for {$database->name} is incomplete");
        }

        return $database;
    }

    /**
     * Whether a dump this panel wrote reached its end.
     *
     * `mysqldump` closes every dump with `-- Dump completed on …` (we run it without `--compact`
     * or `--skip-comments`); a truncated dump lacks it, and size alone cannot tell. Only the last
     * 512 bytes are read. Not a validity check: a complete dump can still fail to import.
     */
    private function looksComplete(string $dump): bool
    {
        $size = (int) filesize($dump);
        $handle = fopen($dump, 'rb');

        if ($handle === false) {
            return false;
        }

        fseek($handle, max(0, $size - 512));
        $tail = (string) fread($handle, 512);
        fclose($handle);

        return str_contains($tail, 'Dump completed');
    }

    public function cleanup(RestoreContext $context, bool $failed): void
    {
        // Nothing local to undo; the safety backup is the way back.
    }
}
