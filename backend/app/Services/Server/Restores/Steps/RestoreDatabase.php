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

        foreach ($dumps as $dump) {
            $name = substr(basename($dump), strlen('db-'), -strlen('.sql'));

            $database = Database::query()
                ->where('application_id', $context->application->id)
                ->where('name', $name)
                ->first();

            if ($database === null) {
                // Recreating a database the panel no longer tracks would leave
                // an orphan with no user and no owner. Naming it is more use
                // than silently skipping it.
                throw new RuntimeException("the database {$name} is no longer attached to this application");
            }

            $engine = $this->databases->engine($database->engine);

            $engine->dropDatabase($database->name);
            $engine->createDatabase($database->name, $database->charset, $database->collation);
            $engine->restore($database->name, $dump);
        }
    }

    public function cleanup(RestoreContext $context, bool $failed): void
    {
        // Nothing local to undo. A failure part-way through leaves the
        // databases restored so far in place; the safety backup is the way
        // back, which is why it is taken before this step can run.
    }
}
