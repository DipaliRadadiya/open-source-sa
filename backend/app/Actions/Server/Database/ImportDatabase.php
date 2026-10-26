<?php

namespace App\Actions\Server\Database;

use App\Enums\ExportStatus;
use App\Enums\ImportStatus;
use App\Exceptions\Server\Database\DatabaseOperationException;
use App\Models\Database;
use App\Models\DatabaseExport;
use App\Models\DatabaseImport;
use App\Models\DatabaseUser;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\Imports\ImportFiles;
use App\Services\Server\Databases\Imports\ImportRefusedException;
use App\Services\Server\Databases\Imports\ImportSanitizer;
use App\Services\Server\Databases\PgsqlEngine;
use Throwable;

/**
 * Replace a database's contents with an uploaded file (operator decision
 * 2026-10-14: replace, not merge — the result is always exactly the file).
 *
 * In this order, and nothing destructive before the third step:
 *
 *  1. **Check** the file: what it is by its bytes, and cleaned into what a
 *     database's own user can load ({@see ImportSanitizer}). A file refused
 *     here has changed nothing.
 *  2. **Back up** the database as it is now — an ordinary export, marked
 *     `import_safety`, kept a week (`exports:prune-import-safety`) or until
 *     the user deletes it. Skipped when the user unticks it (Dipali,
 *     2026-10-26): faster and no extra disk, but a failed load then has
 *     nothing to put back.
 *  3. **Replace**: drop, recreate, give the users back, load the file as the
 *     database's own user.
 *  4. On a failed load, **put the backup back** the same way, as the panel —
 *     it is the panel's own dump.
 *
 * Drop-and-recreate rather than loading over the top for the reason restores
 * do it (RestoreDatabase): a table the file does not mention would otherwise
 * survive, and "the database is now this file" would not be true.
 */
class ImportDatabase
{
    public function __construct(
        private DatabaseManager $manager,
        private ImportFiles $files,
        private ImportSanitizer $sanitizer,
        private ExportDatabase $export,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * The account an import runs as: a user of this database whose password the
     * panel knows and who may connect from this server. Null when there is none
     * — and the import is refused rather than run as the admin account.
     */
    public static function account(Database $database): ?DatabaseUser
    {
        return $database->users()
            ->orderByRaw("CASE connection_preference WHEN 'localhost' THEN 0 WHEN 'anywhere' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->get()
            ->first(fn (DatabaseUser $user): bool => $user->password !== null && $user->password !== ''
                && $user->connection_preference !== 'remote');
    }

    public function execute(DatabaseImport $import): void
    {
        $database = $import->database;

        if ($database === null) {
            $this->fail($import, 'database_missing');

            return;
        }

        $account = self::account($database);

        if ($account === null) {
            $this->fail($import, 'no_account');

            return;
        }

        $import->update(['status' => ImportStatus::Running, 'step' => 'checking', 'started_at' => now()]);

        try {
            [$path, $gzip] = $this->prepare($import, $database);
        } catch (ImportRefusedException $e) {
            $import->update(['line' => $e->lineNumber]);
            $this->fail($import, $e->reason);

            return;
        } catch (DatabaseOperationException $e) {
            $this->fail($import, 'unreadable', $e->reference);

            return;
        }

        $safety = null;

        if ($import->safety_copy) {
            $import->update(['step' => 'backing_up']);
            $safety = $this->backUp($import, $database);

            if ($safety === null) {
                return;
            }
        }

        $import->update(['step' => 'importing']);
        $engine = $this->manager->engine($database->engine);
        $users = $database->users()->pluck('username')->all();

        try {
            $this->recreate($database, $users);
            $engine->importAs($database->name, $path, $account->username, (string) $account->password, $gzip);
        } catch (Throwable $e) {
            $reference = $e instanceof DatabaseOperationException ? $e->reference : null;
            $import->update(['step' => 'rolling_back']);

            $restored = $safety !== null && $this->putBack($database, $users, $safety);

            $this->fail($import, 'load_failed', $reference, $restored);

            return;
        } finally {
            $this->files->delete($import);
        }

        $import->update([
            'status' => ImportStatus::Completed,
            'step' => null,
            'finished_at' => now(),
        ]);

        $this->activityLogger->log(
            'database.import_completed',
            $database,
            ['name' => $database->name, 'file' => $import->original_name],
            actor: $import->user,
        );
    }

    /**
     * @return array{0: string, 1: bool} the file to load, and whether it is gzip
     *
     * @throws ImportRefusedException
     */
    private function prepare(DatabaseImport $import, Database $database): array
    {
        $upload = $this->files->uploadPath($import);
        $info = $this->files->inspect($upload);
        $driver = $this->manager->driver($database->engine);
        $format = $info['format'] ?? null;

        if (($info['gzip'] ?? false) && ! $this->files->gzipComplete($upload)) {
            throw new ImportRefusedException('truncated');
        }

        if ($driver === 'mongo') {
            if ($format !== ImportFiles::MONGO_ARCHIVE) {
                throw new ImportRefusedException('wrong_format');
            }

            return [$upload, $info['gzip']];
        }

        $work = $this->files->workPath($import);

        if ($driver === 'pgsql' && $format === ImportFiles::PG_CUSTOM) {
            $engine = $this->manager->engine($database->engine);
            assert($engine instanceof PgsqlEngine);

            $plain = $work.'.plain';

            try {
                $engine->customToPlain($upload, $plain);
                $this->sanitizer->pgsql($plain, $work);
            } finally {
                @unlink($plain);
            }

            return [$work, false];
        }

        if ($format !== ImportFiles::SQL) {
            throw new ImportRefusedException('wrong_format');
        }

        $driver === 'pgsql'
            ? $this->sanitizer->pgsql($upload, $work)
            : $this->sanitizer->mysql($upload, $work);

        return [$work, false];
    }

    /** The safety copy, or null after recording why it could not be made. */
    private function backUp(DatabaseImport $import, Database $database): ?DatabaseExport
    {
        $safety = DatabaseExport::create([
            'database_id' => $database->id,
            'database_name' => $database->name,
            'engine' => $database->engine,
            'purpose' => DatabaseExport::PURPOSE_IMPORT_SAFETY,
            'status' => ExportStatus::Running,
            'user_id' => $import->user_id,
            'started_at' => now(),
        ]);

        $import->update(['safety_export_id' => $safety->id]);

        try {
            return $this->export->execute($database, $safety);
        } catch (DatabaseOperationException $e) {
            $safety->update([
                'status' => ExportStatus::Failed,
                'reason' => 'dump_failed',
                'reference' => $e->reference,
                'finished_at' => now(),
            ]);

            $this->files->delete($import);
            $this->fail($import, 'backup_failed', $e->reference);

            return null;
        }
    }

    /** @param  array<int, string>  $users */
    private function recreate(Database $database, array $users): void
    {
        $engine = $this->manager->engine($database->engine);

        $engine->dropDatabase($database->name);
        $engine->createDatabase($database->name, $database->charset, $database->collation);
        $engine->reattachUsers($database->name, $users);
    }

    /**
     * Load the safety copy back as the panel. False when that failed too —
     * the copy is still in the Exports list, and the message says so.
     *
     * @param  array<int, string>  $users
     */
    private function putBack(Database $database, array $users, DatabaseExport $safety): bool
    {
        try {
            $this->recreate($database, $users);
            $this->manager->engine($database->engine)->restore(
                $database->name,
                rtrim((string) config('server.databases.export_dir'), '/').'/'.$safety->file,
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function fail(DatabaseImport $import, string $reason, ?string $reference = null, bool $restored = false): void
    {
        $this->files->delete($import);

        $import->update([
            'status' => ImportStatus::Failed,
            'reason' => $reason,
            'reference' => $reference,
            'restored' => $restored,
            'finished_at' => now(),
        ]);

        if ($import->database !== null) {
            $this->activityLogger->log(
                'database.import_failed',
                $import->database,
                ['name' => $import->database_name, 'file' => $import->original_name],
                actor: $import->user,
            );
        }
    }
}
