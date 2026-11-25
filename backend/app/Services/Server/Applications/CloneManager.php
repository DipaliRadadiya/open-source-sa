<?php

namespace App\Services\Server\Applications;

use App\Actions\Server\Application\ConfigureApplicationWebhook;
use App\Actions\Server\Database\DeleteDatabase;
use App\Enums\DomainType;
use App\Exceptions\Server\Application\CloneOperationException;
use App\Models\Application;
use App\Models\ApplicationDomain;
use App\Models\Database;
use App\Models\SiteClone;
use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Applications\Cloning\CloneEnvironment;
use App\Services\Server\Applications\Cloning\DatabaseCopy;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Duplicate any application to a new domain as a fully independent site (unlike Staging
 * there is no "push back"; `cloned_from_application_id` is informational only).
 *
 * Generic for any site type: create the row, provision its vhost with the installer and
 * process start skipped (`rsync` supplies the files), copy files, and only for a type that
 * needs a database involve a `CloneStrategy`.
 */
class CloneManager
{
    /**
     * Same defaults Staging uses.
     *
     * @var array<int, string>
     */
    /**
     * Never worth copying, for any site type: caches, logs and the panel's own state directory.
     */
    private const FILE_EXCLUDES = [
        'wp-content/cache/', '*.log', 'wp-content/upgrade/', '.panel/',
    ];

    /**
     * Dropped from the exclude list for a git application: its clone has to be a working
     * copy (without `.git/` it is not a checkout; without `node_modules/` a Node site cannot
     * start, since a clone runs no deploy). Still excluded for every other type.
     */
    private const GIT_WORKING_COPY = ['.git/', 'node_modules/'];

    public function __construct(
        private ApplicationProvisioner $provisioner,
        private SiteTypeManager $siteTypes,
        private ProcessSupervisor $supervisor,
        private PortAllocator $ports,
        private ServerOps $serverOps,
        private ConfigureApplicationWebhook $webhooks,
        private DatabaseCopy $databaseCopy,
        private ApplicationEnvironment $environment,
        private DeleteDatabase $deleteDatabase,
    ) {}

    /**
     * Run an async clone from the queue, recording named steps.
     *
     * Called by the RunClone job; the Clone record already exists. Creates the target
     * Application and records steps as it progresses.
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

        // forceCreate, not create: `slug` is deliberately not fillable (it names the config
        // file the panel overwrites), so mass assignment would drop it silently.
        $target = Application::forceCreate([
            'system_user_id' => $source->system_user_id,
            'cloned_from_application_id' => $source->id,
            'name' => $name,
            'slug' => Application::uniqueSlug(
                $name,
                alsoTaken: fn (string $slug): bool => app(SlugConflict::class)->for($slug) !== null,
            ),
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
            // The git account the source deploys with (the same stored credential, on the same
            // box and system user, so this grants nothing new) and the commit it is on.
            'git_account_id' => $source->git_account_id,
            'repository' => $source->repository,
            'repository_url' => $source->repository_url,
            'branch' => $source->branch,
            // What is on disk after the copy is the source's commit.
            'last_commit' => $source->last_commit,
            'last_deployed_at' => $source->last_deployed_at,
            // Deliberately NOT copied: `webhook_*` (the identifier is UNIQUE, and a shared
            // secret would deploy the original and the clone on one `git push`).
            'status' => 'pending',
        ]);

        // The Domains screen reads `application_domains`; `applications.domain` only mirrors
        // the primary row, and CreateApplication (which writes it) is not used here.
        // `Application::serverNames()` falls back to the column only while the relation is
        // empty, so the first alias added later would drop the site's own domain from the vhost.
        $target->domains()->create([
            'domain' => strtolower(trim((string) $target->domain)),
            'type' => DomainType::Primary,
            // A generated clone hostname is usually wildcard DNS; certificate actions filter
            // on this flag to avoid spending the shared nip.io rate limit.
            'is_test' => ApplicationDomain::looksTemporary((string) $target->domain),
        ]);

        if ($source->app_port !== null) {
            $target->app_port = $this->ports->allocate();
            $target->save();
        }

        // The source's PHP settings (memory_limit, upload limits, open_basedir,
        // disable_functions, timezone). `replicate()` carries every attribute, so columns
        // added later are cloned too; the two excluded keys are the row's identity and owner.
        //
        // Must happen before provision(): the harden_php step fills disable_functions only
        // when it is null, so a later copy would be overwritten by the strict default.
        if ($source->phpSettings !== null) {
            $settings = $source->phpSettings->replicate(['id', 'application_id']);
            $settings->application_id = $target->id;
            $settings->save();

            $target->unsetRelation('phpSettings');
        }

        // Deploy-on-push, re-armed with a fresh identifier and secret of the clone's own (the
        // source's identifier is UNIQUE and its secret would deploy both sites). The user adds
        // the new URL to the repository themselves. Only when the source used it.
        if ($source->webhook_enabled && $source->site_type === 'git') {
            try {
                $this->webhooks->execute($target, [
                    'enabled' => true,
                    'provider' => $source->webhook_provider ?: $source->gitAccount?->provider,
                ]);

                $target->refresh();
            } catch (Throwable $exception) {
                // An unsupported or missing provider: not worth failing the clone over; it comes
                // up with deploy-on-push off and the next-steps card says to reconnect it.
                Log::warning('clone could not arm deploy-on-push', [
                    'application' => $target->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $target->load('systemUser');

        // Everything from here can fail on the server; a half-made row would hold the unique
        // domain and block a retry, so it is discarded (the SiteClone record keeps the history).
        try {
            // Named steps recorded on the Clone record so the frontend can poll.
            $cloneRecord->update(['current_step' => 'provisioning', 'reason' => null]);
            $this->provisioner->provision($target, skipInstaller: true);

            $cloneRecord->update(['current_step' => 'copying_files']);

            // `codePath()`, not `documentRoot()`: they differ for a git checkout (always at
            // `public_html`, whatever `web_root` says; the `.env` is at `codePath()/.env`) and for
            // Craft/Statamic (project one level above `web/`); copying the served directory
            // would copy only part of the site.
            $this->rsync(
                $this->provisioner->codePath($source),
                $this->provisioner->codePath($target),
                $target,
                $this->workingCopyExcludes($source),
            );

            if ($needsDatabase) {
                $cloneRecord->update(['current_step' => 'cloning_database']);
                $strategy->clone($source, $target);
            } elseif ($siteType?->method() === 'git') {
                $this->cloneLinkedDatabases($cloneRecord, $source, $target);
            }

            if ($this->supervisor->runs($target)) {
                $cloneRecord->update(['current_step' => 'starting_process']);
                // Same path as the copy: a working directory of `public/` cannot find the server.
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
     * A git site's databases, copied, and its `.env` pointed at the copies.
     *
     * A git site has no CloneStrategy; without this the clone would run on the source's
     * database and every change "on the copy" would hit the live site. Each database linked to
     * the source is copied; the `.env` gets the copy's database name, user and password and the
     * clone's domain.
     *
     * The `.env` is written even with no database: one kept above the served directory is not
     * copied by rsync, and a copied one still names the source's URL.
     */
    private function cloneLinkedDatabases(SiteClone $cloneRecord, Application $source, Application $target): void
    {
        $databases = Database::where('application_id', $source->id)->with('users')->get();
        $replace = [];

        if ($databases->isNotEmpty()) {
            $cloneRecord->update(['current_step' => 'cloning_database']);
        }

        foreach ($databases as $database) {
            $copy = $this->databaseCopy->copy($database, $source, $target);
            $user = $copy->users->first();
            $replace[$database->name] = $copy->name;

            foreach ($database->users as $sourceUser) {
                $replace[$sourceUser->username] = $user->username;

                if ((string) $sourceUser->password !== '') {
                    $replace[(string) $sourceUser->password] = (string) $user->password;
                }
            }
        }

        if (! $this->environment->exists($source)) {
            return;
        }

        $this->environment->write($target, CloneEnvironment::rewrite(
            $this->environment->read($source),
            $replace,
            (string) $source->domain,
            (string) $target->domain,
        ), keepPrevious: false);
    }

    /**
     * What a clone may leave behind.
     *
     * Nothing for a git site (it has to be a working copy), and not node_modules for a Node
     * site: a clone runs no deploy, so nothing would install it again.
     *
     * @return list<string>
     */
    private function workingCopyExcludes(Application $source): array
    {
        if ($this->siteTypes->find($source->site_type)?->method() === 'git') {
            return [];
        }

        return $source->serving_profile === 'node'
            ? array_values(array_diff(self::GIT_WORKING_COPY, ['node_modules/']))
            : self::GIT_WORKING_COPY;
    }

    /**
     * Undo the half-made copy of a clone whose job died before its own cleanup (e.g. killed
     * at the time limit mid-copy). Found by what the copy is, not by `target_application_id`,
     * which is only recorded once the copy is finished.
     */
    public function discardAbandoned(SiteClone $cloneRecord): void
    {
        $target = Application::query()
            ->where('cloned_from_application_id', $cloneRecord->source_application_id)
            ->where('domain', $cloneRecord->domain)
            ->where('status', '!=', 'active')
            ->first();

        if ($target !== null) {
            $this->discard($target);
        }
    }

    /**
     * Undo a half-made clone.
     *
     * Deprovisions through the provisioner, not `DeprovisionApplication`, which returns early
     * for a `pending` application and would skip the vhost already written. A cleanup failure
     * is logged and swallowed: the caller is about to report why the clone failed.
     */
    private function discard(Application $application): void
    {
        // A database made for the clone before it failed would be a full copy of the source's
        // data, linked to nothing.
        foreach (Database::where('application_id', $application->id)->with('users')->get() as $database) {
            try {
                $this->deleteDatabase->execute($database);
            } catch (Throwable $cleanupFailure) {
                Log::channel('server-ops')->warning('could not drop a half-made clone\'s database', [
                    'feature' => 'application',
                    'op' => 'clone_discard_database',
                    'application' => $application->id,
                    'database' => $database->name,
                    'detail' => $cleanupFailure->getMessage(),
                ]);
            }
        }

        try {
            // With its files: the copy is the clone's alone.
            $this->provisioner->deprovision($application, removeFiles: $this->ownsItsDirectory($application));
        } catch (Throwable $cleanupFailure) {
            Log::channel('server-ops')->warning('could not deprovision a half-made clone', [
                'feature' => 'application',
                'op' => 'clone_discard',
                'application' => $application->id,
                'detail' => $cleanupFailure->getMessage(),
            ]);
        }

        // The row blocks the retry: it holds the unique domain, name, slug and port.
        $application->delete();
    }

    /**
     * Whether a half-made clone's directory is its own to remove: it needs a slug (without
     * one the path is the user's whole home); slugs are unique, so `{home}/{slug}` is its alone.
     */
    private function ownsItsDirectory(Application $application): bool
    {
        return filled($application->slug) && $application->systemUser !== null;
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
            // A working copy carries `.git` and `node_modules`, usually the bulk of a project.
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
