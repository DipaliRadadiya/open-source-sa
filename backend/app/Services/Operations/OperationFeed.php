<?php

namespace App\Services\Operations;

use App\Models\Backup;
use App\Models\DatabaseExport;
use App\Models\DatabaseImport;
use App\Models\Deployment;
use App\Models\PanelUpdate;
use App\Models\Restore;
use App\Models\RuntimeInstall;
use App\Models\SecurityUpdateRun;
use App\Models\SiteClone;
use App\Models\StagingRun;
use App\Models\SyncRun;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Every long job the panel runs, in one list (RC-20, 2026-10-20).
 *
 * Backups, restores, clones, staging runs, deployments, installs, imports,
 * exports, syncs, security updates and panel updates each live in their own
 * table and showed progress only on the screen that started them. They already
 * share a shape — status, reference, start, end — so this is a read over them,
 * not a new tracking system.
 *
 * "Done" is spelled six ways across their enums, so each row carries a
 * normalised `state` (`waiting` | `running` | `done` | `failed`) beside the
 * kind's own `status` word. Only the kinds the user's role can already see are
 * included: the list must not name sites or databases a role cannot open.
 */
class OperationFeed
{
    /** Rows per kind, and in the whole answer. */
    public const PER_KIND = 50;

    public const LIMIT = 100;

    private const STATE = [
        'pending' => 'waiting', 'queued' => 'waiting', 'uploading' => 'waiting',
        'running' => 'running', 'verifying' => 'running', 'installing' => 'running', 'removing' => 'running',
        'succeeded' => 'done', 'completed' => 'done', 'verified' => 'done', 'ready' => 'done',
        'failed' => 'failed',
    ];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function for(User $user, bool $activeOnly, CarbonInterface $since, ?CarbonInterface $finishedAfter = null): Collection
    {
        return collect($this->kinds())
            ->filter(fn (array $kind) => $this->allowed($user, $kind['permission']))
            ->flatMap(function (array $kind) use ($activeOnly, $since, $finishedAfter) {
                $model = $kind['model'];
                $active = array_keys(array_filter(self::STATE, fn ($state) => in_array($state, ['waiting', 'running'], true)));

                $query = $model::query()->with($kind['with'] ?? []);

                // Not ->when(): it calls a closure value itself before passing it on.
                if (isset($kind['scope'])) {
                    $kind['scope']($query);
                }

                $rows = $query
                    ->where(fn (Builder $query) => $activeOnly
                        ? $query->whereIn('status', $active)
                            // RC-36: and what ended since the caller last looked.
                            ->when($finishedAfter !== null, fn (Builder $query) => $query->orWhere('finished_at', '>', $finishedAfter))
                        : $query->whereIn('status', $active)->orWhere('created_at', '>=', $since))
                    ->latest('id')
                    ->limit(self::PER_KIND)
                    ->get();

                return $rows->map(fn (Model $row) => $this->row($kind, $row));
            })
            ->sortByDesc('created_at_sort')
            ->take(self::LIMIT)
            ->map(fn (array $row) => collect($row)->except('created_at_sort')->all())
            ->values();
    }

    /** @param array<string, mixed> $kind */
    private function row(array $kind, Model $row): array
    {
        $status = $row->status instanceof BackedEnum ? $row->status->value : (string) $row->status;
        $state = self::STATE[$status] ?? 'running';

        // A staging run whose worker died reports itself failed (RC-16).
        if ($row instanceof StagingRun && $row->isStranded()) {
            $state = 'failed';
        }

        return [
            'kind' => $kind['kind'],
            'id' => $row->getKey(),
            'state' => $state,
            'status' => $status,
            'subject' => $kind['subject']($row),
            'step' => $kind['step'] ? $row->{$kind['step']} : null,
            'reference' => $row->reference ?? null,
            'user_id' => $row->user_id ?? null,
            'created_at' => $row->created_at?->format('d-m-Y H:i:s'),
            'started_at' => $row->started_at?->format('d-m-Y H:i:s'),
            'finished_at' => $row->finished_at?->format('d-m-Y H:i:s'),
            'finished_at_human' => $row->finished_at?->diffForHumans(),
            'created_at_sort' => $row->created_at?->getTimestamp() ?? 0,
        ];
    }

    private function allowed(User $user, ?string $permission): bool
    {
        return $permission === null ? $user->isAdmin() : $user->canView($permission);
    }

    /** @return array<string, mixed>|null */
    private static function application(?Model $application): ?array
    {
        return $application === null ? null : ['type' => 'application', 'id' => $application->id, 'name' => $application->name];
    }

    /** @return list<array<string, mixed>> */
    private function kinds(): array
    {
        $server = fn () => ['type' => 'server', 'id' => null, 'name' => null];
        $database = fn (?int $id, ?string $name) => $id === null && $name === null ? null : ['type' => 'database', 'id' => $id, 'name' => $name];

        return [
            ['kind' => 'backup', 'model' => Backup::class, 'permission' => 'backup', 'step' => null, 'with' => ['application:id,name'],
                'subject' => fn (Backup $b) => $b->application_id !== null ? self::application($b->application) : $database($b->database_id, $b->subject_name)],
            ['kind' => 'restore', 'model' => Restore::class, 'permission' => 'backup', 'step' => 'current_step', 'with' => ['application:id,name', 'database:id,name', 'backup'],
                'subject' => fn (Restore $r) => $r->application_id !== null ? self::application($r->application) : $database($r->database_id, $r->database?->name ?? $r->backup?->subject_name)],
            ['kind' => 'clone', 'model' => SiteClone::class, 'permission' => 'app_clone', 'step' => 'current_step', 'with' => ['sourceApplication:id,name'],
                'subject' => fn (SiteClone $c) => self::application($c->sourceApplication)],
            ['kind' => 'staging', 'model' => StagingRun::class, 'permission' => 'app_staging', 'step' => 'kind', 'with' => ['application:id,name'],
                'subject' => fn (StagingRun $s) => self::application($s->application)],
            ['kind' => 'deployment', 'model' => Deployment::class, 'permission' => 'app_deployment', 'step' => 'failed_step', 'with' => ['application:id,name'],
                'subject' => fn (Deployment $d) => self::application($d->application)],
            ['kind' => 'database_export', 'model' => DatabaseExport::class, 'permission' => 'database', 'step' => null,
                'subject' => fn (DatabaseExport $e) => $database($e->database_id, $e->database_name)],
            ['kind' => 'database_import', 'model' => DatabaseImport::class, 'permission' => 'database', 'step' => 'step',
                'subject' => fn (DatabaseImport $i) => $database($i->database_id, $i->database_name)],
            ['kind' => 'runtime_install', 'model' => RuntimeInstall::class, 'permission' => 'php', 'step' => 'current_step',
                'scope' => fn (Builder $q) => $q->where('runtime', 'php'),
                'subject' => fn (RuntimeInstall $r) => ['type' => 'server', 'id' => null, 'name' => trim('PHP '.$r->version.' '.$r->extension)]],
            ['kind' => 'runtime_install', 'model' => RuntimeInstall::class, 'permission' => 'node', 'step' => 'current_step',
                'scope' => fn (Builder $q) => $q->where('runtime', 'node'),
                'subject' => fn (RuntimeInstall $r) => ['type' => 'server', 'id' => null, 'name' => 'Node '.$r->version]],
            ['kind' => 'sync', 'model' => SyncRun::class, 'permission' => 'sync', 'step' => null, 'subject' => $server],
            ['kind' => 'security_update', 'model' => SecurityUpdateRun::class, 'permission' => 'setting', 'step' => null, 'subject' => $server],
            ['kind' => 'panel_update', 'model' => PanelUpdate::class, 'permission' => null, 'step' => 'current_step', 'subject' => $server],
        ];
    }
}
