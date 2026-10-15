<?php

namespace App\Services\Server\AppShell;

use App\Models\Application;
use App\Services\Server\Applications\ContainerAccess;
use App\Services\Server\ServerOps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The access map: which containers each system user may log in to (RC-28).
 *
 * The one thing the root helper trusts. One entry per Docker app —
 * `{app, id, owner, project, containers: [{service, name, main}]}` — built from
 * the database and each app's stored compose ({@see ContainerAccess}), and
 * written root:root 0600 through a temporary file and a rename, so the helper
 * never reads half of one.
 *
 * Rebuilt whole every time, never patched: the database is the truth, the
 * file is a copy of it, and a full rebuild cannot drift. It is small (one line
 * per container) and only rewritten when its content changes.
 *
 * **The `app` name** is what a user types after `@` when they own several
 * Docker apps. It is the app's slug — already unique, lowercase, made of
 * `a-z 0-9 -`, and the name of its folder — when that is at most 40
 * characters; otherwise, or if another entry already uses it, `app-<id>`.
 */
class AppShellMap
{
    /** sha256 of the content last written, so an unchanged map is not rewritten. */
    private const WRITTEN = 'app_shell.map.sha256';

    private const SLUG = '/^[a-z0-9][a-z0-9-]{0,39}$/D';

    public function __construct(
        private ContainerAccess $access,
        private AppShellFiles $files,
        private ServerOps $serverOps,
    ) {}

    /**
     * The `@<app>` name of every Docker app, by id.
     *
     * @return array<int, string>
     */
    public function names(): array
    {
        $names = [];
        $taken = [];

        foreach ($this->dockerApps()->get(['id', 'slug']) as $application) {
            $slug = (string) $application->slug;
            $name = preg_match(self::SLUG, $slug) === 1 && ! isset($taken[$slug]) ? $slug : 'app-'.$application->id;

            for ($n = 2; isset($taken[$name]); $n++) {
                $name = 'app-'.$application->id.'-'.$n;
            }

            $taken[$name] = true;
            $names[(int) $application->id] = $name;
        }

        return $names;
    }

    public function nameOf(Application $application): string
    {
        return $this->names()[(int) $application->id] ?? 'app-'.$application->id;
    }

    /**
     * @return list<array{app: string, id: int, owner: string, project: string, containers: list<array{service: string, name: string, main: bool}>}>
     */
    public function entries(): array
    {
        $names = $this->names();
        $entries = [];

        foreach ($this->dockerApps()->with('systemUser')->get() as $application) {
            $owner = $application->systemUser?->username;

            if (! is_string($owner) || $owner === '') {
                continue;
            }

            try {
                $containers = $this->access->containers($application);
            } catch (Throwable) {
                // A Simple-mode site with no container port yet has no file and
                // so nothing to log in to; it appears once it has one.
                $containers = [];
            }

            if ($containers === []) {
                continue;
            }

            // Exactly one main container: the helper opens it by default. A file
            // where nothing publishes the app's port defaults to its first service.
            if (! in_array(true, array_column($containers, 'main'), true)) {
                $containers[0]['main'] = true;
            }

            $entries[] = [
                'app' => $names[(int) $application->id] ?? 'app-'.$application->id,
                'id' => (int) $application->id,
                'owner' => $owner,
                'project' => 'sv-app-'.$application->id,
                'containers' => $containers,
            ];
        }

        return $entries;
    }

    /**
     * Docker apps owned by this system user.
     */
    public function dockerAppsOwnedBy(int $systemUserId): int
    {
        return $this->dockerApps()->where('system_user_id', $systemUserId)->count();
    }

    public function render(): string
    {
        return json_encode(
            ['version' => 1, 'apps' => $this->entries()],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /**
     * Rebuild after something that can change it (an app, an owner, a user).
     *
     * Never throws and never fails the operation that triggered it: an app
     * that was created is still created if its owner cannot log in to it yet,
     * and the next rebuild — including the one every panel update runs —
     * brings the map back in step.
     */
    public function refresh(): void
    {
        if (! config('server.app_shell.sync', true)) {
            return;
        }

        try {
            $this->write();
        } catch (Throwable $exception) {
            Log::channel('server-ops')->warning('container login map not rewritten', [
                'feature' => 'app_shell', 'op' => 'map',
                'exception' => $exception::class, 'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Write the map if it changed, or always with `$force`.
     *
     * @return bool|null true written, false unchanged, null failed
     */
    public function write(bool $force = false): ?bool
    {
        if (! $this->files->configured()) {
            return null;
        }

        $content = $this->render();
        $hash = hash('sha256', $content);

        if (! $force && Cache::get(self::WRITTEN) === $hash) {
            return false;
        }

        $path = $this->files->mapPath();
        $temporary = $path.'.new';
        $context = ['feature' => 'app_shell', 'op' => 'map'];

        // The directory is 0700, so the moment `tee` creates the file with
        // root's default mode nobody else can see into it anyway.
        foreach ([
            fn () => $this->serverOps->run(['install', '-d', '-m', '0700', '-o', 'root', '-g', 'root', dirname($path)], $context),
            fn () => $this->serverOps->run(['tee', $temporary], $context, input: $content),
            fn () => $this->serverOps->run(['chmod', '0600', $temporary], $context),
            fn () => $this->serverOps->run(['mv', '-f', $temporary, $path], $context),
        ] as $step) {
            if ($step()->failed()) {
                Cache::forget(self::WRITTEN);

                return null;
            }
        }

        Cache::forever(self::WRITTEN, $hash);

        return true;
    }

    private function dockerApps(): Builder
    {
        return Application::query()->where('serving_profile', 'docker')->orderBy('id');
    }
}
