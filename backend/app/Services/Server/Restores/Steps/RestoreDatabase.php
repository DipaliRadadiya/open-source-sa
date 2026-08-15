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
 * Drop, recreate, import — not import-over-the-top. A plain import replaces
 * every table the dump contains, but a table created *after* the backup
 * survives it, leaving a schema that is a mix of two points in time. That is a
 * miserable bug to find months later, and "restore" has to mean the state in
 * the backup, not a merge with it.
 *
 * Grants live on the user rather than the schema, so dropping the database
 * does not cost the application its credentials.
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
            // A database restore with no dump in the archive would otherwise
            // report success having done nothing at all — the worst possible
            // outcome for someone who just watched their site get replaced.
            throw new RuntimeException('this backup contains no database dump');
        }

        // Everything is checked before anything is dropped.
        //
        // These checks used to live inside the loop below, which meant the
        // first database could already have been dropped and replaced before
        // the second was found to be missing or empty — a restore that half
        // happened, on data the user cannot get back without going to the
        // safety backup by hand. Nothing here touches the server.
        $plan = [];

        foreach ($dumps as $dump) {
            $name = substr(basename($dump), strlen('db-'), -strlen('.sql'));

            // An empty dump is the one that costs the most: dropping a live
            // database to import nothing leaves the site with no data at all.
            // `DumpDatabase` already refuses to *write* an empty dump for the
            // same reason; this is that check on the way back in, for an
            // archive that was truncated in transit.
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
                // Recreating a database the panel no longer tracks would leave
                // an orphan with no user and no owner. Naming it is more use
                // than silently skipping it.
                throw new RuntimeException("the database {$name} is no longer attached to this application");
            }

            $plan[] = [$database, $dump];
        }

        foreach ($plan as [$database, $dump]) {
            $engine = $this->databases->engine($database->engine);

            $engine->dropDatabase($database->name);
            $engine->createDatabase($database->name, $database->charset, $database->collation);
            $engine->restore($database->name, $dump);
        }
    }

    /**
     * Whether a dump this panel wrote reached its end.
     *
     * `mysqldump` closes every dump with `-- Dump completed on …`, and we run it
     * without `--compact` or `--skip-comments`, so ours always carry it. A dump
     * that stops short does not — which is exactly what a download cut off part
     * way, or an archive that unpacked incompletely, leaves behind. Size alone
     * cannot tell those apart: a truncated dump is a perfectly plausible size.
     *
     * Only the last few hundred bytes are read; these files are gigabytes.
     *
     * This is not a validity check. A dump can be complete and still fail to
     * import, and the safety backup remains the answer to that. It rules out
     * the failure that actually happens — the file did not all arrive — before
     * anything is dropped for it.
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
        // Nothing local to undo. A failure part-way through leaves the
        // databases restored so far in place; the safety backup is the way
        // back, which is why it is taken before this step can run.
    }
}
