<?php

namespace App\Actions\Server\Application;

use App\Actions\Server\Database\DeleteDatabase;
use App\Exceptions\Server\ServerOperationException;
use App\Models\Database;
use App\Services\Server\Applications\ApplicationArtifacts;
use App\Support\DatabaseRemovalOutcome;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Remove the databases a site delete was asked to take with it.
 *
 * **Each database is independent.** They run in sequence, but a failure in one
 * must not skip the rest — one unreachable engine would otherwise leave every
 * other database of that site behind, for a reason that had nothing to do with
 * them. That is the same shape as the backup-orphan bug in
 * {@see ApplicationArtifacts::removeBackups()}:
 * one `foreach` inside one `try`, and the first failure ends the loop.
 *
 * A database that fails keeps its panel row — {@see DeleteDatabase} leaves it
 * deliberately, so the same delete can be retried from the databases screen
 * until it finishes. That is why the row is worth keeping even though the
 * application it belonged to is gone: `databases.application_id` is
 * `nullOnDelete`, so what is left is a detached but fully visible database,
 * not an orphan nothing can reach.
 */
class DeleteApplicationDatabases
{
    public function __construct(private DeleteDatabase $deleteDatabase) {}

    /**
     * @param  Collection<int, Database>  $databases  read before the application row was deleted
     */
    public function execute(Collection $databases, int $applicationId): DatabaseRemovalOutcome
    {
        $deleted = [];
        $failed = [];

        foreach ($databases as $database) {
            try {
                $this->deleteDatabase->execute($database);
                $deleted[] = $database->name;
            } catch (Throwable $e) {
                // Server-operation failures already carry a reference that
                // correlates to the raw stderr in the server-ops log. Anything
                // else gets one minted here, so the answer never contains a
                // failure the user cannot quote.
                $reference = $e instanceof ServerOperationException
                    ? $e->reference
                    : (string) Str::uuid();

                Log::channel('server-ops')->warning('a database outlived the application it belonged to', [
                    'feature' => 'application',
                    'op' => 'remove_databases',
                    'application' => $applicationId,
                    'database' => $database->name,
                    'engine' => $database->engine,
                    'reference' => $reference,
                    'detail' => $e->getMessage(),
                ]);

                $failed[] = [
                    'name' => $database->name,
                    'engine' => $database->engine,
                    'reference' => $reference,
                ];
            }
        }

        return new DatabaseRemovalOutcome($deleted, $failed);
    }
}
