<?php

namespace App\Services\Server\Applications;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\Server\ServerOpsResult;

/**
 * Writes the record of one deploy while it happens.
 *
 * Opened before the job is dispatched so the screen can show `queued` until a worker picks it up.
 * Output is accumulated step by step, so a deploy that dies halfway still leaves what it said.
 */
class DeploymentRecorder
{
    private ?Deployment $deployment = null;

    /** @var array<int, string> */
    private array $output = [];

    public function open(Application $application, DeploymentTrigger $trigger, ?int $userId): Deployment
    {
        $this->output = [];

        $this->deployment = Deployment::create([
            'application_id' => $application->id,
            'user_id' => $userId,
            'trigger' => $trigger,
            'status' => DeploymentStatus::Queued,
            'branch' => $application->branch ?: 'main',
        ]);

        $this->prune($application);

        return $this->deployment;
    }

    /** Attach to a row the queuing request opened (the job runs in a worker). */
    public function resume(Deployment $deployment): void
    {
        $this->deployment = $deployment;
        $this->output = [];

        $deployment->update([
            'status' => DeploymentStatus::Running,
            'started_at' => now(),
        ]);
    }

    /** Record a finished step and whatever it printed. */
    public function step(string $step, ?ServerOpsResult $result = null): void
    {
        if ($this->deployment === null) {
            return;
        }

        $steps = $this->deployment->steps ?? [];

        // One stage can take more than one command; the list is the stages, not the commands.
        if (end($steps) !== $step) {
            $steps[] = $step;
        }

        if ($result !== null) {
            $text = trim($result->output()."\n".$result->errorOutput());

            if ($text !== '') {
                $this->output[] = "$ {$step}\n".$text;
            }
        }

        $this->deployment->update([
            'steps' => $steps,
            'output' => $this->redact(implode("\n\n", $this->output)),
        ]);
    }

    /**
     * Record which commit is now checked out, as soon as the working tree is at it.
     *
     * Called before ownership, the deploy script, restarts and verify: those steps can fail, and
     * the commit is already on disk by then.
     */
    public function commit(?string $commit, ?string $message, ?string $author): void
    {
        $this->deployment?->update([
            'commit_hash' => $commit,
            'commit_message' => $message,
            'commit_author' => $author,
        ]);
    }

    public function succeed(?string $commit, ?string $message, ?string $author): void
    {
        $this->deployment?->update([
            'status' => DeploymentStatus::Succeeded,
            'commit_hash' => $commit,
            'commit_message' => $message,
            'commit_author' => $author,
            'finished_at' => now(),
        ]);
    }

    /**
     * The commit is deliberately not touched here: whatever {@see commit()} recorded stands, and
     * a failure before checkout has none to show.
     */
    public function fail(string $step, ?string $reference, ?string $reason = null): void
    {
        $this->deployment?->update([
            'status' => DeploymentStatus::Failed,
            'failed_step' => $step,
            'failed_reason' => $reason,
            'reference' => $reference,
            'finished_at' => now(),
        ]);
    }

    public function current(): ?Deployment
    {
        return $this->deployment;
    }

    /**
     * Strip anything that looks like a credential. Not optional: a failed `git clone` echoes a
     * private repository's URL with its token, and npm and composer print registry credentials
     * on auth failures. Patterns, not a list of known secrets.
     */
    public function redact(string $output): string
    {
        $patterns = [
            // https://user:token@host
            '#(https?://)[^/\s:@]+:[^/\s@]+@#i' => '$1***:***@',

            // Bearer / token / password / secret / api_key followed by a value.
            '#\b(authorization|bearer|token|password|passwd|secret|api[_-]?key)\b(\s*[:=]\s*|\s+)(\S+)#i' => '$1$2***',

            // GitHub, GitLab and npm tokens have recognisable prefixes.
            '#\b(gh[pousr]_|glpat-|npm_)[A-Za-z0-9_\-]{8,}#' => '$1***',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $output) ?? $output;
    }

    /**
     * Keep the newest N and delete the rest. Done on write, not by a scheduled command, so it
     * runs even where the scheduler was never set up.
     */
    private function prune(Application $application): void
    {
        $keep = (int) config('server.deployments.keep', 50);

        $cutoff = Deployment::query()
            ->where('application_id', $application->id)
            ->orderByDesc('id')
            ->skip($keep)
            ->take(1)
            ->value('id');

        if ($cutoff !== null) {
            Deployment::query()
                ->where('application_id', $application->id)
                ->where('id', '<=', $cutoff)
                ->delete();
        }
    }
}
