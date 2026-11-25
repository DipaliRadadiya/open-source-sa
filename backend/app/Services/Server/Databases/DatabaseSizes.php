<?php

namespace App\Services\Server\Databases;

use App\Models\Database;
use Throwable;

/**
 * Keeps `databases.size_bytes` current.
 *
 * The column is the cache the list page reads, so it does not query every schema per request;
 * this class refreshes it.
 */
class DatabaseSizes
{
    public function __construct(private DatabaseManager $manager) {}

    /**
     * Re-measure one database. Returns it either way: an unreadable size must not fail the request.
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
     * Re-measure one database and say why when it could not be: `engine_unreachable` when the
     * engine does not answer, otherwise `unmeasured`; null when the stored size is now current.
     *
     * The stored size is left as it was either way (a new database's 0 would read as "empty").
     */
    public function refreshReporting(Database $database): ?string
    {
        $size = $this->measure($database);

        if ($size === null) {
            return $this->engineAnswers((string) $database->engine) ? 'unmeasured' : 'engine_unreachable';
        }

        if ($size !== (int) $database->size_bytes) {
            $database->forceFill(['size_bytes' => $size])->save();
        }

        return null;
    }

    /**
     * Re-measure everything the panel tracks.
     *
     * Grouped by engine so a stopped engine costs one failed probe, not one per database.
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
     * Null when the size could not be read, which is different from zero: writing a failed probe
     * as 0 would report every database on a stopped engine as empty.
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
