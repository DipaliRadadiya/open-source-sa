<?php

namespace App\Services\Server\Applications;

use App\Actions\Server\Application\ConfigureApplicationWebhook;
use App\Enums\DomainType;
use App\Exceptions\Server\Application\CloneOperationException;
use App\Models\Application;
use App\Models\ApplicationDomain;
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
    /**
     * Never worth copying, for any site type: caches that rebuild themselves,
     * logs that belong to the site they were written on, and the panel's own
     * state directory.
     */
    private const FILE_EXCLUDES = [
        'wp-content/cache/', '*.log', 'wp-content/upgrade/', '.panel/',
    ];

    /**
     * Dropped from the exclude list for a git application, because a clone of
     * one has to be a *working copy*.
     *
     * `.git/` was excluded, so the copy was not a checkout: no remote, no
     * branch, no HEAD. Nothing could be deployed from it and `git status` in it
     * was an error. `node_modules/` was excluded, so a Node site could not
     * start until something rebuilt it — and nothing does, because a clone does
     * not run a deploy.
     *
     * They are still excluded everywhere else. A WordPress install has no
     * checkout to preserve, and its `node_modules` (a theme build directory)
     * is genuinely disposable.
     */
    private const GIT_WORKING_COPY = ['.git/', 'node_modules/'];

    public function __construct(
        private ApplicationProvisioner $provisioner,
        private SiteTypeManager $siteTypes,
        private ProcessSupervisor $supervisor,
        private PortAllocator $ports,
        private ServerOps $serverOps,
        private ConfigureApplicationWebhook $webhooks,
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
            // The account the source deploys with, and the commit it is
            // actually on.
            //
            // `git_account_id` was the one git column left behind, so the clone
            // came up with a repository, a branch and no way to reach either:
            // `git_account_missing` true, the Deployment screen asking to
            // relink, and the user re-picking credentials the panel already
            // holds. Copying a foreign key to the same stored credential grants
            // nothing new — the clone runs on the same box, as the same system
            // user, against the same repository.
            'git_account_id' => $source->git_account_id,
            'repository' => $source->repository,
            'repository_url' => $source->repository_url,
            'branch' => $source->branch,
            // What is on disk after the copy, which is the source's commit. A
            // clone reporting "never deployed" while serving deployed code is
            // the Deployment screen lying about the site in front of you.
            'last_commit' => $source->last_commit,
            'last_deployed_at' => $source->last_deployed_at,
            // Deliberately NOT copied: `webhook_*`. `webhook_identifier` is
            // UNIQUE, so it cannot be, and duplicating the secret would make one
            // `git push` deploy the original and the clone together — which is
            // the opposite of what a copy is for. Deploy-on-push stays off until
            // it is switched on deliberately.
            'status' => 'pending',
        ]);

        // The Domains screen reads `application_domains`, not this column —
        // `applications.domain` is only the mirror of whichever row is
        // primary. CreateApplication writes that row; this path does not go
        // through it, so a cloned site came up serving its domain with a
        // completely empty Domains section.
        //
        // Worse than cosmetic. `Application::serverNames()` falls back to this
        // column only while the relation is empty, so adding a single alias
        // afterwards made the relation non-empty *without* the primary in it —
        // and the next vhost dropped the site's own domain.
        $target->domains()->create([
            'domain' => strtolower(trim((string) $target->domain)),
            'type' => DomainType::Primary,
            // A generated clone hostname is usually a wildcard-DNS name, and the
            // certificate actions filter on this flag to avoid spending the
            // shared nip.io rate limit.
            'is_test' => ApplicationDomain::looksTemporary((string) $target->domain),
        ]);

        if ($source->app_port !== null) {
            $target->app_port = $this->ports->allocate();
            $target->save();
        }

        // The source's PHP settings, before provisioning reads them.
        //
        // The clone copied php_version and stopped there, so a cloned site
        // came up with the interpreter its source used and none of the
        // configuration around it: memory_limit, the upload limits,
        // open_basedir, disable_functions, the timezone. A site cloned to
        // reproduce a problem did not reproduce the environment the problem
        // lived in, and one cloned as a staging copy quietly ran on different
        // limits than the site it was standing in for.
        //
        // `replicate()` rather than naming columns: it carries every attribute
        // the model has, so a setting added to the table later is cloned
        // without anyone remembering to come back here. The two excluded keys
        // are the two that must not travel — the identity of the row and its
        // owner.
        //
        // Before provision(), deliberately. The harden_php step reads this row
        // and fills disable_functions only when it is null, so a copy that
        // landed afterwards would be written over by the strict default and
        // the inheritance would silently not happen.
        if ($source->phpSettings !== null) {
            $settings = $source->phpSettings->replicate(['id', 'application_id']);
            $settings->application_id = $target->id;
            $settings->save();

            $target->unsetRelation('phpSettings');
        }

        // Deploy-on-push, re-armed with credentials of the clone's own.
        //
        // The source's identifier cannot be reused — it is UNIQUE — and its
        // secret must not be, because one `git push` would then deploy both
        // sites. So the clone gets a fresh pair, and the result screen shows
        // the new URL with the one instruction that cannot be automated:
        // add it to the repository. Nothing on this box can register a webhook
        // on somebody's GitHub.
        //
        // Only when the source actually used it. Minting credentials for a
        // webhook nobody asked for would leave a live endpoint with no traffic
        // and no reason to exist.
        if ($source->webhook_enabled && $source->site_type === 'git') {
            try {
                $this->webhooks->execute($target, [
                    'enabled' => true,
                    'provider' => $source->webhook_provider ?: $source->gitAccount?->provider,
                ]);

                $target->refresh();
            } catch (Throwable $exception) {
                // An unsupported or missing provider. The copy is otherwise
                // fine, so it is not worth failing the clone over — it comes up
                // with deploy-on-push off, exactly as it did before, and the
                // next-steps card says to reconnect it.
                Log::warning('clone could not arm deploy-on-push', [
                    'application' => $target->id,
                    'message' => $exception->getMessage(),
                ]);
            }
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

            // `codePath()`, not `documentRoot()`.
            //
            // They are the same directory for most site types, and for those
            // this changes nothing. They are NOT the same for the two shapes
            // that build a project around the served directory:
            //
            //  - a git checkout always lands at `public_html`, whatever
            //    `web_root` says — so a repository whose front controller is in
            //    `public/` had only `public/` copied. Not the application
            //    source, not composer.json, and not the `.env`, which
            //    `ApplicationEnvironment` writes at `codePath()/.env` — one
            //    level above what was being copied.
            //  - Craft and Statamic put `craft`/`please` and the whole project
            //    one level above `web/`, and were cloned just as partially.
            //
            // The old behaviour produced a clone of the served assets and
            // called it a copy of the site.
            $this->rsync(
                $this->provisioner->codePath($source),
                $this->provisioner->codePath($target),
                $target,
                $this->siteTypes->find($source->site_type)?->method() === 'git'
                    ? []
                    : self::GIT_WORKING_COPY,
            );

            if ($needsDatabase) {
                $cloneRecord->update(['current_step' => 'cloning_database']);
                $strategy->clone($source, $target);
            }

            if ($this->supervisor->runs($target)) {
                $cloneRecord->update(['current_step' => 'starting_process']);
                // Same path as the copy: a Node process whose working
                // directory is `public/` cannot find the server it is meant to
                // start.
                $this->supervisor->apply($target, $this->provisioner->codePath($target), start: true);
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
            // Raised with the payload. A served directory was the old scope; a
            // working copy carries `.git` and `node_modules` too, which on a
            // real project is usually the bulk of it.
            timeout: (int) config('server.clone.rsync_timeout', 900),
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
