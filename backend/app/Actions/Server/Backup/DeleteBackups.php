<?php

namespace App\Actions\Server\Backup;

use App\Models\Backup;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Delete several backups, reporting each one's outcome separately.
 *
 * Every deletion goes through {@see DeleteBackup} rather than reimplementing
 * the order it establishes — archive first, row second — because the two
 * disagreeing is how an object gets orphaned in a customer's bucket, and a
 * second copy of that logic is the obvious way for them to drift.
 *
 * A batch half-working is a first-class outcome, not an edge case. Twenty
 * backups where one is mid-run must delete the nineteen and say which one it
 * did not: failing the whole request hides nineteen deletions that the user
 * would then try again, and succeeding quietly hides the one still sitting in
 * the bucket being paid for. This mirrors the file manager's bulk contract
 * (`succeeded` / `failed` with a reason) so a client reads one shape for both.
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
                // The refusals DeleteBackup raises deliberately: a run in
                // flight, or an archive that could not be removed. Both are
                // answers about that one backup, so they belong beside it
                // rather than failing the batch.
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
     * The message is already translated into the *actor's* locale by the time
     * it reaches here, so passing it through would put a rendered sentence in
     * an API field and make the client's own wording impossible.
     */
    private function reason(ValidationException $e): string
    {
        $message = (string) ($e->errors()['backup'][0] ?? '');

        return $message === __('backup.errors.delete_running') ? 'running' : 'artifact';
    }
}
