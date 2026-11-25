<?php

namespace App\Actions\Server\Backup;

use App\Contracts\PreparesDownloads;
use App\Jobs\FetchBackupForDownload;
use App\Models\Backup;
use App\Models\BackupDownloadPreparation;
use App\Models\User;
use App\Services\Server\Backups\Downloads\PreparedDownloads;
use App\Services\Server\Backups\Storage\StorageDriverFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Start copying an SFTP/FTP backup to the panel, or hand back the copy already on its way.
 *
 * Idempotent: a second click on "Preparing…" must not start a second multi-gigabyte download.
 * A copy that failed, expired or was stranded by a dead worker is replaced instead.
 *
 * Everything that can be refused without the storage server (including a disk too full for the
 * copy) is refused here, before queueing. What needs the server is the job's to find out.
 */
class PrepareBackupDownload
{
    public function __construct(
        private PreparedDownloads $downloads,
        private StorageDriverFactory $drivers,
    ) {}

    /**
     * @return array{preparation: BackupDownloadPreparation, created: bool}
     *
     * @throws ValidationException
     */
    public function execute(Backup $backup, ?User $user): array
    {
        $key = $backup->manifest['key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.download_no_artifact')],
            ]);
        }

        $destination = $backup->destination();

        if ($destination === null) {
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.download_no_destination')],
            ]);
        }

        // S3 and Drive hand the browser a link of their own; copying their
        // archives through the panel would only be slower.
        if (! $this->drivers->for($destination) instanceof PreparesDownloads) {
            throw ValidationException::withMessages([
                'backup' => [__('backup.errors.download_prepare_not_needed')],
            ]);
        }

        // Serialised per backup so two simultaneous clicks cannot both start a copy; the unique
        // index on `backup_id` would only make the second request fail.
        return Cache::lock('backup-download-prepare:'.$backup->getKey(), 10)->block(5, function () use ($backup, $user): array {
            $existing = BackupDownloadPreparation::query()->where('backup_id', $backup->getKey())->first();

            if ($existing !== null && ! $existing->isSpent()) {
                return ['preparation' => $existing, 'created' => false];
            }

            $this->refuseWithoutSpace($backup);

            if ($existing !== null) {
                $this->downloads->discard($existing);
            }

            $preparation = BackupDownloadPreparation::create([
                'backup_id' => $backup->getKey(),
                'user_id' => $user?->getKey(),
                'reference' => (string) Str::uuid(),
                'status' => BackupDownloadPreparation::PREPARING,
                'size_bytes' => $backup->size_bytes,
            ]);

            FetchBackupForDownload::dispatch($preparation->id)->onQueue('default');

            return ['preparation' => $preparation, 'created' => true];
        });
    }

    /**
     * Refused now rather than failed later: a copy that fills the disk takes the sites on it
     * down with it.
     */
    private function refuseWithoutSpace(Backup $backup): void
    {
        $shortfall = $this->downloads->shortfall($backup->size_bytes);

        if ($shortfall === null) {
            return;
        }

        throw ValidationException::withMessages([
            'backup' => [__('backup.errors.download_no_space', [
                'needed' => Number::fileSize($shortfall['needed'], 1),
                'free' => Number::fileSize($shortfall['free'], 1),
            ])],
        ]);
    }
}
