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
 * Opened before the job is dispatched so the screen can show `queued` in the
 * seconds before a worker picks it up — without that a user who clicks Deploy
 * sees nothing and clicks again.
 *
 * Output is accumulated step by step rather than at the end, so a deploy that
 * dies halfway still leaves everything it managed to say. A crash that erases
 * the evidence of itself is the worst possible outcome for this feature.
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

    /**
     * Attach to a row somebody else opened — the job runs in a worker, and the
     * row was written in the request that queued it.
     */
    public function resume(Deployment $deployment): void
    {
        $this->deployment = $deployment;
        $this->output = [];

        $deployment->update([
            'status' => DeploymentStatus::Running,
            'started_at' => now(),
        ]);
    }

    /**
     * Record a finished step and whatever it printed.
     */
    public function step(string $step, ?ServerOpsResult $result = null): void
    {
        if ($this->deployment === null) {
            return;
        }

        $steps = $this->deployment->steps ?? [];
        $steps[] = $step;

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

    public function fail(string $step, ?string $reference): void
    {
        $this->deployment?->update([
            'status' => DeploymentStatus::Failed,
            'failed_step' => $step,
            'reference' => $reference,
            'finished_at' => now(),
        ]);
    }

    public function current(): ?Deployment
    {
        return $this->deployment;
    }

    /**
     * Strip anything that looks like a credential.
     *
     * Not optional. A failed `git clone` echoes the URL it tried, which for a
     * private repository carries the token; npm and composer print registry
     * credentials on an auth failure. Everything else in this codebase keeps
     * secrets out of logs — writing raw output to a column the API then renders
     * in a browser would undo all of it in one place.
     *
     * Patterns rather than a list of known secrets, because the interesting
     * case is the one nobody thought of.
     */
    public function redact(string $output): string
    {
        $patterns = [
            // https://user:token@host — the shape a git remote takes when a
            // credential leaks into it.
            '#(https?://)[^/\s:@]+:[^/\s@]+@#i' => '$1***:***@',

            // Bearer / token / password / secret / api_key followed by a value,
            // in any of the punctuation styles these tools print.
            '#\b(authorization|bearer|token|password|passwd|secret|api[_-]?key)\b(\s*[:=]\s*|\s+)(\S+)#i' => '$1$2***',

            // Long opaque strings that look like credentials in their own
            // right: GitHub, GitLab and npm all use recognisable prefixes.
            '#\b(gh[pousr]_|glpat-|npm_)[A-Za-z0-9_\-]{8,}#' => '$1***',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $output) ?? $output;
    }

    /**
     * Keep the newest N and delete the rest.
     *
     * Bounded on write rather than by a scheduled command: the growth is caused
     * by deploying, so the fix belongs where the growth happens and cannot be
     * left un-run on a box whose scheduler was never set up.
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
