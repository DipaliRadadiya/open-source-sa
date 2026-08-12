<?php

namespace App\Services\Server\Sync\Discoverers;

use App\Contracts\Discoverable;
use App\Models\Cronjob;
use App\Models\SyncRun;
use App\Models\SystemUser;
use App\Services\Server\ServerOps;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Scheduled jobs already running for a site's account.
 *
 * The resource people notice last and miss most: a cron job that silently
 * stops after a migration is discovered a week later, by whatever did not
 * happen.
 *
 * Two sources, because both are in use: `/etc/cron.d` files, which is where
 * this panel and the one before it write, and a user's personal crontab,
 * which is where a developer puts things by hand.
 *
 * Only jobs belonging to an account the panel manages are reported. Root's
 * cron is the operating system's — certbot renewals, log rotation, apt
 * timers — and a panel that offered to manage those would eventually be
 * asked to delete one.
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

        if ($users->isEmpty()) {
            return [];
        }

        $tracked = Cronjob::query()->pluck('command', 'slug');

        return array_merge(
            $this->fromCronD($users, $tracked),
            $this->fromUserCrontabs($users, $tracked),
        );
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
                // `name` is unique in this table. Two jobs legitimately run
                // the same command on different schedules — a nightly backup
                // and a midday one — and taking the command as the name made
                // the second collide and be recorded as a failure.
                'name' => $this->uniqueName($attributes['name']),
                'username' => $attributes['username'],
                'system_user_id' => $attributes['system_user_id'],
                'command' => $attributes['command'],
                'expression' => $attributes['expression'],
                // True, because it *is* running. This is the one resource
                // where the panel writing its own copy does not double up:
                // adopting records the job, and the file it came from stays
                // exactly where it is until someone changes it here.
                'active' => true,
            ],
        );
    }

    /**
     * A name no other cron job has.
     *
     * `cronjobs.name` is unique, and two jobs legitimately run the same
     * command on different schedules — a nightly backup and a midday one.
     * Taking the command as the name made the second collide and be recorded
     * as a failure, which reads as "the panel could not import this" when the
     * job was perfectly fine.
     *
     * Same shape as Application::uniqueName rather than a new idea, and local
     * rather than on the model because only adoption derives a name; a job
     * created in the panel has one the user typed.
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
            );

            // "no crontab for x" exits non-zero. The common case, not an error.
            if ($result->failed()) {
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
    private function item($users, string $username, string $expression, string $command, array $evidence): array
    {
        // The slug names the file the panel would write. Derived from the
        // command so re-running the sync produces the same one, and suffixed
        // with a hash because two jobs can legitimately run the same command
        // on different schedules.
        $slug = Str::slug(Str::limit($command, 30, '')).'-'.substr(md5($username.$expression.$command), 0, 6);

        return [
            'key' => $username.':'.$slug,
            'label' => Str::limit($command, 60),
            // Read verbatim from a file. The only judgement is whose it is,
            // and that came from the file too.
            'confidence' => 100,
            'evidence' => $evidence + [
                'username' => $username,
                'expression' => $expression,
                'command' => $command,
            ],
            'attributes' => [
                'slug' => $slug,
                'name' => Str::limit($command, 60),
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
