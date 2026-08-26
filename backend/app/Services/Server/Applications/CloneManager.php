<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\CloneOperationException;
use App\Models\Application;
use App\Models\SiteClone;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Duplicate any application to a brand-new domain as a fully independent
 * site — no ongoing relationship to the source, unlike Staging (there is no
 * "push back"; `cloned_from_application_id` is informational only).
 *
 * Generic for any site type: create the row, provision its vhost with the
 * marketplace installer and process-start skipped (there are no files yet —
 * `rsync` supplies them a moment later, not a fresh install), copy files,
 * then only if the type actually needs a database does a `CloneStrategy`
 * get involved at all. A type with no database (static, blank PHP, node,
 * git with no database) never touches the strategy hook.
 */
class CloneManager
{
    /**
     * Same defaults Staging uses — caches, VCS metadata, build artifacts,
     * the panel's own bookkeeping directory don't belong in a clone either.
     *
     * @var array<int, string>
     */
    private const FILE_EXCLUDES = [
        'wp-content/cache/', '.git/', 'node_modules/', '*.log', 'wp-content/upgrade/', '.panel/',
    ];

    public function __construct(
        private ApplicationProvisioner $provisioner,
        private SiteTypeManager $siteTypes,
        private ProcessSupervisor $supervisor,
        private PortAllocator $ports,
        private ServerOps $serverOps,
    ) {}

    /**
     * Run an async clone from the queue, recording named steps.
     *
     * Called by RunClone job. The Clone record already exists (created by
     * the controller before dispatching the job); this method creates the
     * target Application and records steps as it progresses.
     */
    public function execute(SiteClone $cloneRecord): Application
    {
        $source = $cloneRecord->sourceApplication;
        $source->load('systemUser');

        $domain = $cloneRecord->domain;

        $siteType = $this->siteTypes->find($source->site_type);
        $needsDatabase = $siteType?->needsDatabase() ?? false;
        $strategy = $siteType?->cloneStrategy();

        if ($needsDatabase && $strategy === null) {
            throw new CloneOperationException((string) Str::uuid());
        }

        $name = $cloneRecord->name ?? Application::uniqueName("{$source->name} (Clone)");

        // forceCreate, not create: `slug` is deliberately not fillable — it
        // names the web-server config file the panel overwrites, so a client
        // must never choose it — which means mass assignment drops it in
        // silence and the site provisions into the system user's home.
        $target = Application::forceCreate([
            'system_user_id' => $source->system_user_id,
            'cloned_from_application_id' => $source->id,
            'name' => $name,
            'slug' => Application::uniqueSlug($name),
            'domain' => $domain,
            'site_type' => $source->site_type,
            'serving_profile' => $source->serving_profile,
            'rendering_type' => $source->rendering_type,
            'php_version' => $source->php_version,
            'node_version' => $source->node_version,
            'web_root' => $source->web_root,
            'build_command' => $source->build_command,
            'deploy_script' => $source->deploy_script,
            'start_command' => $source->start_command,
            'repository' => $source->repository,
            'repository_url' => $source->repository_url,
            'branch' => $source->branch,
            'status' => 'pending',
        ]);

        if ($source->app_port !== null) {
            $target->app_port = $this->ports->allocate();
            $target->save();
        }

        $target->load('systemUser');

        // Everything from here can fail on the server, and the row already
        // exists. Left behind it holds the domain — which is unique — so
        // retrying the same clone was refused with a validation error, and the
        // panel listed a site that had never been created. The SiteClone
        // record keeps the history; the half-made application does not.
        try {
            // Named steps recorded on the Clone record so the frontend can poll.
            $cloneRecord->update(['current_step' => 'provisioning', 'reason' => null]);
            $this->provisioner->provision($target, skipInstaller: true);

            $cloneRecord->update(['current_step' => 'copying_files']);
            $this->rsync(
                $this->provisioner->documentRoot($source),
                $this->provisioner->documentRoot($target),
                $target,
            );

            if ($needsDatabase) {
                $cloneRecord->update(['current_step' => 'cloning_database']);
                $strategy->clone($source, $target);
            }

            if ($this->supervisor->runs($target)) {
                $cloneRecord->update(['current_step' => 'starting_process']);
                $this->supervisor->apply($target, $this->provisioner->documentRoot($target), start: true);
            }
        } catch (Throwable $e) {
            $this->discard($target);

            throw $e;
        }

        $target->status = 'active';
        $target->save();

        // Update Clone record with the completed target's id.
        $cloneRecord->update(['target_application_id' => $target->id]);

        return $target->fresh();
    }

    /**
     * Undo a half-made clone.
     *
     * Deprovision goes through the provisioner rather than
     * `DeprovisionApplication`, which returns early for a `pending`
     * application — and a clone that failed on the way up is exactly that, so
     * the action would skip the vhost it did manage to write.
     *
     * The cleanup's own failure is logged and swallowed: the caller is about
     * to report why the clone failed, and that is the message worth keeping.
     */
    private function discard(Application $application): void
    {
        try {
            $this->provisioner->deprovision($application);
        } catch (Throwable $cleanupFailure) {
            Log::channel('server-ops')->warning('could not deprovision a half-made clone', [
                'feature' => 'application',
                'op' => 'clone_discard',
                'application' => $application->id,
                'detail' => $cleanupFailure->getMessage(),
            ]);
        }

        // The row is what blocks the retry: it holds the unique domain, the
        // unique name and slug, and any port allocated above.
        $application->delete();
    }

    /**
     * @param  list<string>  $excludes
     */
    private function rsync(string $source, string $destination, Application $owner, array $excludes = []): void
    {
        $patterns = array_merge(self::FILE_EXCLUDES, $excludes);
        $args = [];

        foreach ($patterns as $pattern) {
            $args[] = '--exclude';
            $args[] = $pattern;
        }

        $result = $this->serverOps->run(
            array_merge(['rsync', '-a'], $args, [rtrim($source, '/').'/', rtrim($destination, '/').'/']),
            ['feature' => 'application', 'op' => 'clone_rsync', 'application' => $owner->id],
            timeout: 300,
        );

        if ($result->failed()) {
            throw new CloneOperationException($result->reference);
        }

        $ownership = $this->serverOps->run(
            ['chown', '-R', "{$owner->systemUser->username}:{$owner->systemUser->username}", $destination],
            ['feature' => 'application', 'op' => 'clone_rsync_chown', 'application' => $owner->id],
        );

        if ($ownership->failed()) {
            throw new CloneOperationException($ownership->reference, $ownership->busy, $ownership->staleLock);
        }
    }
}
