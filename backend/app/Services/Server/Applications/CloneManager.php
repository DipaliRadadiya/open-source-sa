<?php

namespace App\Services\Server\Applications;

use App\Exceptions\Server\Application\CloneOperationException;
use App\Models\Application;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\ServerOps;
use Illuminate\Support\Str;

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

    public function clone(Application $source, string $domain): Application
    {
        $siteType = $this->siteTypes->find($source->site_type);
        $needsDatabase = $siteType?->needsDatabase() ?? false;
        $strategy = $siteType?->cloneStrategy();

        if ($needsDatabase && $strategy === null) {
            // Refuse rather than produce a clone whose config still points
            // at the source's own database — only WordPress has the "give
            // it a fresh database and rewrite its URLs" recipe built so far.
            throw new CloneOperationException((string) Str::uuid());
        }

        $source->load('systemUser');

        $clone = Application::create([
            'system_user_id' => $source->system_user_id,
            'cloned_from_application_id' => $source->id,
            'name' => "{$source->name} (Clone)",
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
            // Repository metadata is copied for reference; deploy-on-push is
            // deliberately not — see webhook note below.
            'repository' => $source->repository,
            'repository_url' => $source->repository_url,
            'branch' => $source->branch,
            'status' => 'pending',
            // `webhook_enabled` stays false and `webhook_identifier` stays
            // null: it has a unique constraint, so copying it verbatim would
            // fail the insert outright — and even if it didn't, two
            // applications answering to one deploy-on-push identity is
            // exactly the kind of ambiguity a clone must not introduce.
        ]);

        if ($source->app_port !== null) {
            $clone->app_port = $this->ports->allocate();
            $clone->save();
        }

        $clone->load('systemUser');

        $this->provisioner->provision($clone, skipInstaller: true);

        $this->rsync(
            $this->provisioner->documentRoot($source),
            $this->provisioner->documentRoot($clone),
            $clone,
        );

        if ($needsDatabase) {
            $strategy->clone($source, $clone);
        }

        if ($this->supervisor->runs($clone)) {
            $this->supervisor->apply($clone, $this->provisioner->documentRoot($clone), start: true);
        }

        $clone->status = 'active';
        $clone->save();

        return $clone->fresh();
    }

    private function rsync(string $source, string $destination, Application $owner): void
    {
        $excludes = array_merge(
            ...array_map(fn (string $pattern) => ['--exclude', $pattern], self::FILE_EXCLUDES),
        );

        $result = $this->serverOps->run(
            array_merge(['rsync', '-a'], $excludes, [rtrim($source, '/').'/', rtrim($destination, '/').'/']),
            ['feature' => 'application', 'op' => 'clone_rsync', 'application' => $owner->id],
            timeout: 300,
        );

        if ($result->failed()) {
            throw new CloneOperationException($result->reference);
        }

        $this->serverOps->run(
            ['chown', '-R', "{$owner->systemUser->username}:{$owner->systemUser->username}", $destination],
            ['feature' => 'application', 'op' => 'clone_rsync_chown', 'application' => $owner->id],
        );
    }
}
