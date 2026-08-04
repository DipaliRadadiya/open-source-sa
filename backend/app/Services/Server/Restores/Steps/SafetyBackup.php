<?php

namespace App\Services\Server\Restores\Steps;

use App\Contracts\RestoreStep;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Services\Server\Backups\BackupRunner;
use App\Services\Server\Restores\RestoreContext;
use RuntimeException;

/**
 * Backs up the current state before it is overwritten.
 *
 * This is the step that makes the whole feature defensible. Without it a
 * restore is a one-way door: pick the wrong backup from a list of similar
 * timestamps and the state you were in is gone with no way to describe what
 * was lost. With it, the wrong choice costs a few minutes.
 *
 * It is **not** optional and **not** a checkbox. A user who unticks it is
 * making a decision they cannot evaluate — they do not yet know the restore
 * will be wrong — so the panel makes it for them.
 *
 * If the safety backup fails, the restore stops. That will occasionally annoy
 * someone whose bucket is full; it is the correct trade against silently
 * removing the only way back at the exact moment it was about to be needed.
 */
class SafetyBackup implements RestoreStep
{
    /** Safety backups kept per target. Bounded, or they accumulate forever. */
    private const KEEP = 2;

    public function __construct(private BackupRunner $backups) {}

    public function key(): string
    {
        return 'safety_backup';
    }

    public function appliesTo(RestoreContext $context): bool
    {
        return true;
    }

    public function run(RestoreContext $context): void
    {
        $target = $context->backup->target;

        if ($target === null) {
            throw new RuntimeException('the backup settings this backup came from no longer exist');
        }

        // The configured target, whatever it covers — not just the half being
        // restored. Restoring only the database still changes the site, and a
        // half safety backup is a half way back.
        $safety = $this->backups->run($target, $context->restore->user_id);

        if ($safety->status !== BackupStatus::Verified) {
            throw new RuntimeException(
                'the safety backup did not complete, so nothing was restored',
            );
        }

        $safety->update(['is_safety' => true]);

        $context->restore->update(['safety_backup_id' => $safety->id]);

        $this->pruneOlderSafetyBackups($target->id, $safety->id);
    }

    public function cleanup(RestoreContext $context, bool $failed): void
    {
        // The safety backup outlives the restore on purpose — it is the way
        // back, and cleaning it up would defeat the entire point of taking it.
    }

    /**
     * Safety backups are exempt from the target's retention, so they need
     * their own bound or a site restored twenty times keeps twenty archives.
     */
    private function pruneOlderSafetyBackups(int $targetId, int $keepId): void
    {
        Backup::query()
            ->where('backup_target_id', $targetId)
            ->where('is_safety', true)
            ->whereKeyNot($keepId)
            ->orderByDesc('id')
            ->skip(self::KEEP - 1)
            ->take(100)
            ->get()
            ->each
            ->delete();
    }
}
