<?php

namespace App\Services\Server\Databases;

use App\Models\Database;
use Throwable;

/**
 * Keeps `databases.size_bytes` honest.
 *
 * The column was written once, when a database was created or adopted, and
 * never touched again — so it reported the size at creation, which is
 * essentially zero, for the rest of the database's life. A 5 GB database read
 * as empty.
 *
 * The fix is refreshing it, not caching it: the column already *is* the cache,
 * and the list page reads it precisely so it does not have to query every
 * schema on every request. What was missing is anything that puts a current
 * number in it.
 */
class DatabaseSizes
{
    public function __construct(private DatabaseManager $manager) {}

    /**
     * Re-measure one database. Returns it either way — a size that could not be
     * read is not a reason to fail the request that asked for the database.
     */
    public function refresh(Database $database): Database
    {
        $size = $this->measure($database);

        if ($size !== null && $size !== (int) $database->size_bytes) {
            $database->forceFill(['size_bytes' => $size])->save();
        }

        return $database;
    }

    /**
     * Re-measure everything the panel tracks.
     *
     * Grouped by engine so an engine that is stopped costs one failed probe
     * rather than one per database sitting on it.
     *
     * @return int How many rows were updated.
     */
    public function refreshAll(): int
    {
        $updated = 0;

        foreach (Database::query()->get()->groupBy('engine') as $engine => $databases) {
            if (! $this->engineAnswers((string) $engine)) {
                continue;
            }

            foreach ($databases as $database) {
                $size = $this->measure($database);

                if ($size !== null && $size !== (int) $database->size_bytes) {
                    $database->forceFill(['size_bytes' => $size])->save();
                    $updated++;
                }
            }
        }

        return $updated;
    }

    /**
     * Null when the size could not be read, which is different from zero.
     * Writing a failed probe as 0 would report every database on a stopped
     * engine as empty — the same silent-wrong-answer the stale column gave.
     */
    private function measure(Database $database): ?int
    {
        try {
            return $this->manager->engine($database->engine)->databaseSize($database->name);
        } catch (Throwable) {
            return null;
        }
    }

    private function engineAnswers(string $engine): bool
    {
        try {
            return $this->manager->engine($engine)->version() !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
