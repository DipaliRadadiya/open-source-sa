<?php

namespace App\Services\Server\Sync\Discoverers;

use App\Contracts\Discoverable;
use App\Models\Cronjob;
use App\Models\SyncRun;
use App\Models\SystemUser;
use App\Rules\NoShellComment;
use App\Services\Server\ServerOps;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Scheduled jobs already running for a site's account.
 *
 * Two sources: `/etc/cron.d` files (where this panel writes) and a user's personal crontab
 * (where a developer edits by hand).
 *
 * Only jobs of accounts the panel manages are reported. Root's cron belongs to the operating
 * system (certbot, log rotation, apt timers); the panel must never offer to manage or delete it.
 */
class CronjobDiscoverer implements Discoverable
{
    public function __construct(private ServerOps $serverOps) {}

    public function resourceType(): string
    {
        return 'cronjob';
    }

    public function dependsOn(): array
    {
        // Jobs are attributed to the account that runs them.
        return ['system_user'];
    }

    public function discover(SyncRun $run): array
    {
        $users = SystemUser::query()->pluck('id', 'username');

        // Accounts a preview would adopt first. See SyncRun::previewedSystemUsers().
        foreach ($run->previewedSystemUsers()->keys() as $username) {
            if (! $users->has($username)) {
                $users->put($username, null);
            }
        }

        if ($users->isEmpty()) {
            return [];
        }

        $tracked = Cronjob::query()->pluck('command', 'slug');

        // Skip jobs already adopted: fromCronD() only knows the panel's own file names, and an
        // adopted job keeps its original file until its first edit. The slug is stable per run.
        return array_values(array_filter(
            array_merge(
                $this->fromCronD($users, $tracked),
                $this->fromUserCrontabs($users, $tracked),
            ),
            fn (array $item): bool => ! $tracked->has($item['attributes']['slug']),
        ));
    }

    public function adopt(array $item): ?object
    {
        $attributes = $item['attributes'] ?? [];

        $existing = Cronjob::query()->where('slug', $attributes['slug'])->first();

        if ($existing !== null) {
            return $existing;
        }

        return Cronjob::create(
            [
                'slug' => $attributes['slug'],
                // Where it was read from, so the first edit or delete can remove it. The slug
                // names the file the panel would write, not the one it found.
                'source_path' => $attributes['source_path'] ?? null,
                'source_line' => $attributes['source_line'] ?? null,
                // `name` is unique, but two jobs may run the same command on different schedules.
                'name' => $this->uniqueName($attributes['name']),
                'username' => $attributes['username'],
                'system_user_id' => $attributes['system_user_id'],
                'command' => $attributes['command'],
                'expression' => $attributes['expression'],
                // It is already running; the original file stays untouched until edited here.
                'active' => true,
            ],
        );
    }

    /**
     * A name no other cron job has.
     *
     * `cronjobs.name` is unique, but two jobs may run the same command on different schedules.
     * Same shape as Application::uniqueName; local because only adoption derives a name.
     */
    private function uniqueName(string $name): string
    {
        $candidate = $name;
        $suffix = 2;

        while (Cronjob::query()->where('name', $candidate)->exists()) {
            $candidate = $name.' '.$suffix++;
        }

        return $candidate;
    }

    /**
     * `/etc/cron.d` — six fields, the user in the middle.
     *
     * @param  Collection<string, int>  $users
     * @param  Collection<string, string>  $tracked
     * @return array<int, array<string, mixed>>
     */
    private function fromCronD($users, $tracked): array
    {
        $directory = rtrim((string) config('server.cron_d', '/etc/cron.d'), '/');
        $found = [];

        foreach ($this->files($directory) as $path) {
            $slug = basename($path);

            // A file this panel wrote, for a job it already knows.
            if ($tracked->has($slug)) {
                continue;
            }

            $contents = $this->read($path);

            if ($contents === null) {
                continue;
            }

            foreach ($this->lines($contents) as $line) {
                // min hour dom mon dow USER command…
                if (! preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(\S+)\s+(.+)$/', $line, $matches)) {
                    continue;
                }

                [, $expression, $username, $command] = $matches;

                if (! $users->has($username)) {
                    continue;
                }

                $found[] = $this->item(
                    $users,
                    $username,
                    $expression,
                    $command,
                    ['source' => 'cron.d', 'path' => $path],
                );
            }
        }

        return $found;
    }

    /**
     * `crontab -l -u <user>` — five fields, the user is the crontab's owner.
     *
     * @param  Collection<string, int>  $users
     * @param  Collection<string, string>  $tracked
     * @return array<int, array<string, mixed>>
     */
    private function fromUserCrontabs($users, $tracked): array
    {
        $found = [];

        foreach ($users as $username => $id) {
            $result = $this->serverOps->run(
                ['crontab', '-l', '-u', (string) $username],
                ['feature' => 'sync', 'op' => 'read_user_crontab', 'system_user' => $username],
                timeout: 30,
                expectedExitCodes: [1], // "no crontab for x"
            );

            // "no crontab for x" exits non-zero: the common case, not an error.
            // A denied sudo also exits non-zero (sudoers predating `crontab` in the allowlist);
            // log it so it does not pass as a clean scan that found nothing.
            if ($result->failed()) {
                if (str_contains($result->errorOutput(), 'password is required')) {
                    Log::warning('crontab discovery skipped: the panel may not run crontab', [
                        'reference' => $result->reference,
                        'system_user' => $username,
                    ]);
                }

                continue;
            }

            foreach ($this->lines($result->output()) as $line) {
                if (! preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(.+)$/', $line, $matches)) {
                    continue;
                }

                [, $expression, $command] = $matches;

                $found[] = $this->item(
                    $users,
                    (string) $username,
                    $expression,
                    $command,
                    ['source' => 'crontab', 'user' => $username],
                    $line,
                );
            }
        }

        return $found;
    }

    /**
     * @param  Collection<string, int>  $users
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function item($users, string $username, string $expression, string $command, array $evidence, ?string $crontabLine = null): array
    {
        // Split off a trailing `# note`: the panel appends `>> log 2>&1`, which a comment would
        // swallow, and the save rule refuses one. The note becomes the job's name.
        $note = null;
        $original = $command;

        if (($at = NoShellComment::commentStart($command)) !== null) {
            $note = trim(substr($command, $at + 1));
            $command = rtrim(substr($command, 0, $at));
        }

        // The slug names the file the panel would write: derived from the command, plus a hash
        // because two jobs may share a command. Taken from the line as found, so jobs adopted
        // before the note split keep their slug.
        $slug = Str::slug(Str::limit($original, 30, '')).'-'.substr(md5($username.$expression.$original), 0, 6);

        return [
            'key' => $username.':'.$slug,
            'label' => Str::limit($command, 60),
            // Read verbatim from a file.
            'confidence' => 100,
            'evidence' => $evidence + [
                'username' => $username,
                'expression' => $expression,
                'command' => $command,
            ],
            'attributes' => [
                'slug' => $slug,
                // Where the job came from, so the first edit or delete can remove the original
                // (a cron.d file whole, or the exact crontab line); else it would run twice.
                'source_path' => $evidence['source'] === 'cron.d'
                    ? $evidence['path']
                    : 'crontab:'.$username,
                'source_line' => $crontabLine,
                'name' => Str::limit($note !== null && $note !== '' ? $note : $command, 60),
                'username' => $username,
                'system_user_id' => $users->get($username),
                'expression' => $expression,
                'command' => $command,
            ],
        ];
    }

    /**
     * Real schedule lines only — comments, blanks and the environment
     * assignments cron files begin with (`SHELL=`, `PATH=`, `MAILTO=`).
     *
     * @return array<int, string>
     */
    private function lines(string $contents): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $contents) ?: []),
            fn (string $line): bool => $line !== ''
                && ! str_starts_with($line, '#')
                && ! preg_match('/^[A-Z_]+\s*=/', $line),
        ));
    }

    /** @return array<int, string> */
    private function files(string $directory): array
    {
        $listing = $this->serverOps->run(
            ['find', $directory, '-maxdepth', '1', '-type', 'f'],
            ['feature' => 'sync', 'op' => 'discover_cronjobs'],
            timeout: 30,
        );

        if ($listing->failed()) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', trim($listing->output())) ?: []),
            fn (string $path): bool => $path !== '',
        ));
    }

    private function read(string $path): ?string
    {
        $result = $this->serverOps->run(
            ['cat', $path],
            ['feature' => 'sync', 'op' => 'read_cron_file'],
            timeout: 30,
        );

        return $result->failed() ? null : $result->output();
    }
}
