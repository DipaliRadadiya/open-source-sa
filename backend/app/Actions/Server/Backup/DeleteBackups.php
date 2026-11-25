<?php

namespace App\Actions\Server\Backup;

use App\Models\Backup;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Delete several backups, reporting each one's outcome separately.
 *
 * Every deletion goes through {@see DeleteBackup} to keep its order (archive first, row second);
 * a second copy of that logic could orphan an object in the customer's bucket.
 *
 * A half-working batch is a normal outcome: the backups that can be deleted are deleted and the
 * ones that cannot are reported, never failing the whole request or hiding the failure. Mirrors
 * the file manager's bulk contract (`succeeded` / `failed` with a reason).
 */
class DeleteBackups
{
    public function __construct(private DeleteBackup $deleteBackup) {}

    /**
     * @param  iterable<Backup>  $backups
     * @return array{succeeded: list<int>, failed: list<array{id: int, reason: string}>}
     */
    public function execute(iterable $backups): array
    {
        $succeeded = [];
        $failed = [];

        foreach ($backups as $backup) {
            try {
                $this->deleteBackup->execute($backup);
                $succeeded[] = $backup->id;
            } catch (ValidationException $e) {
                // Deliberate refusals from DeleteBackup (a run in flight, an archive that could not
                // be removed) are answers about that one backup, not a reason to fail the batch.
                $failed[] = ['id' => $backup->id, 'reason' => $this->reason($e)];
            } catch (Throwable $e) {
                report($e);
                $failed[] = ['id' => $backup->id, 'reason' => 'failed'];
            }
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    /**
     * Which refusal this was, as a stable key rather than the sentence.
     *
     * The message is already in the *actor's* locale, so passing it through would put a rendered
     * sentence in an API field.
     */
    private function reason(ValidationException $e): string
    {
        $message = (string) ($e->errors()['backup'][0] ?? '');

        return $message === __('backup.errors.delete_running') ? 'running' : 'artifact';
    }
}
