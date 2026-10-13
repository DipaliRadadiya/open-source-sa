<?php

namespace App\Observers;

use App\Models\Application;
use App\Models\SystemUser;
use App\Services\Server\AppShell\AppShellMap;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps the container login map in step with the database (RC-28).
 *
 * Every way the map can change is a write to one of two models: a Docker app
 * created (including a clone or a staging copy), deleted, given a new compose
 * file or a new owner; a system user renamed or deleted. Observing the models
 * rather than calling the map from each action is what makes "every way"
 * true — a path added later is covered without anyone remembering to.
 *
 * After commit, so a transaction that rolls back never leaves the map naming
 * an app that does not exist. The map itself skips the write when nothing in
 * it changed, so the many status saves during provisioning cost one render.
 */
class AppShellMapObserver implements ShouldHandleEventsAfterCommit
{
    /** Application columns the map (or the file it is read from) depends on. */
    private const APPLICATION_FIELDS = [
        'system_user_id', 'serving_profile', 'slug', 'compose', 'app_port',
        'container_port', 'image', 'site_type',
    ];

    public function __construct(private AppShellMap $map) {}

    public function saved(Model $model): void
    {
        if ($this->relevant($model)) {
            $this->map->refresh();
        }
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof SystemUser || ($model instanceof Application && $this->wasDocker($model))) {
            $this->map->refresh();
        }
    }

    private function relevant(Model $model): bool
    {
        if ($model instanceof SystemUser) {
            return $model->wasChanged('username');
        }

        if (! $model instanceof Application || ! $this->wasDocker($model)) {
            return false;
        }

        return $model->wasRecentlyCreated || $model->wasChanged(self::APPLICATION_FIELDS);
    }

    private function wasDocker(Application $application): bool
    {
        return $application->serving_profile === 'docker'
            || $application->getOriginal('serving_profile') === 'docker'
            || ($application->getChanges()['serving_profile'] ?? null) !== null;
    }
}
