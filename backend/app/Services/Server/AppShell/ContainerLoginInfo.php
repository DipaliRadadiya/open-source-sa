<?php

namespace App\Services\Server\AppShell;

use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Recipes\RecipeRegistry;

/**
 * The container-login half of the Command-line access card (RC-28).
 *
 * Everything here is about the app's OWN system user, the one way in: whether
 * container login is on for it, and the exact lines to type. The strings are
 * built here, not in the browser, so the card shows what the helper on the
 * server actually accepts, and there is one place that knows when `-p` and
 * `@<app>` are needed.
 */
class ContainerLoginInfo
{
    public function __construct(
        private AppShellMap $map,
        private RecipeRegistry $recipes,
    ) {}

    /**
     * @return array{owner: array<string, mixed>|null, app: string, commands: array{shell: string, exec_example: string, logs: string}|null, cli_examples: list<array{label: string, command: string}>}
     */
    public function for(Application $application, string $host, int $port): array
    {
        $owner = $application->systemUser;
        $app = $this->map->nameOf($application);

        if (! $owner instanceof SystemUser) {
            return ['owner' => null, 'app' => $app, 'commands' => null, 'cli_examples' => []];
        }

        // `@<app>` only when the owner has several Docker apps to choose from:
        // with one, the helper opens it by default and the shorter line works.
        $pick = $this->map->dockerAppsOwnedBy($owner->id) > 1 ? $app : null;

        $ssh = 'ssh '.($port !== 22 ? "-p {$port} " : '').$owner->username.'@'.$host;
        $prefix = $pick !== null ? "@{$pick} " : '';

        $examples = [];

        foreach ($this->recipes->find((string) $application->site_type)?->cliExamples ?? [] as $example) {
            $examples[] = [
                'label' => (string) $example['label'],
                'command' => $ssh.' '.$this->quote($prefix.$example['command']),
            ];
        }

        return [
            'owner' => [
                'username' => $owner->username,
                'ssh_access' => (bool) $owner->ssh_access,
                'container_login' => (bool) $owner->container_login,
                'has_password' => $owner->password !== null,
                'ssh_keys' => $owner->sshKeys()->count(),
                'other_apps' => $this->otherApps($owner),
            ],
            'app' => $app,
            'commands' => [
                'shell' => 'ssh -t '.substr($ssh, 4).($pick !== null ? " @{$pick}" : ''),
                'exec_example' => $ssh.' '.$this->quote($prefix.'ls -la'),
                'logs' => $ssh.' @logs'.($pick !== null ? " {$pick}" : '').' --tail 100',
            ],
            'cli_examples' => $examples,
        ];
    }

    /**
     * Apps this user owns that are NOT Docker apps.
     *
     * With container login on, the user's SSH and SFTP open a container and
     * nothing else, so these lose the way their files were reached. The API
     * asks for an explicit confirmation when this is not zero.
     */
    public function otherApps(SystemUser $owner): int
    {
        return Application::query()
            ->where('system_user_id', $owner->id)
            ->where(fn ($query) => $query->whereNull('serving_profile')->orWhere('serving_profile', '!=', 'docker'))
            ->count();
    }

    /**
     * One shell word, single-quoted, for the terminal it is pasted into.
     */
    private function quote(string $command): string
    {
        return "'".str_replace("'", "'\\''", $command)."'";
    }
}
