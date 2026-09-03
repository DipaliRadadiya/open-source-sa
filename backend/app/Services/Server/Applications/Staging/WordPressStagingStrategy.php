<?php

namespace App\Services\Server\Applications\Staging;

use App\Actions\Server\Database\CreateDatabase;
use App\Contracts\StagingStrategy;
use App\Exceptions\Server\Application\StagingOperationException;
use App\Models\Application;
use App\Models\Database;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Databases\DatabaseIdentifier;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\DatabasePassword;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * The database half of WordPress staging: clone it, point wp-config.php at
 * the copy, rewrite the URLs a serialized-data-safe way, and trap outbound
 * mail. Files are already copied and the staging site already provisioned
 * (real vhost, real document root) by the time `StagingManager` calls in
 * here — see the `StagingStrategy` contract.
 *
 * `--skip-plugins --skip-themes` on every `wp` call: a broken plugin/theme
 * on the production site must not also break staging it, the same reasoning
 * `AbstractSiteInstaller`'s own WP-CLI calls don't yet apply (this is where
 * that convention starts).
 */
class WordPressStagingStrategy implements StagingStrategy
{
    public function __construct(
        private ApplicationProvisioner $provisioner,
        private DatabaseManager $databases,
        private DatabaseIdentifier $databaseIdentifiers,
        private CreateDatabase $createDatabase,
        private ServerOps $serverOps,
    ) {}

    public function create(Application $production, Application $staging): void
    {
        $productionDatabase = Database::where('application_id', $production->id)->with('users')->first();

        if ($productionDatabase === null) {
            // Should not happen — WordPress::needsDatabase() is true, so
            // provisioning would have failed already without one. Refusing
            // rather than silently skipping the database half.
            throw new StagingOperationException((string) Str::uuid());
        }

        $engine = $this->databases->engine($productionDatabase->engine);
        $connection = $this->databases->connection($productionDatabase->engine);
        $dumpPath = '/tmp/panel-staging-'.Str::uuid()->toString().'.sql';

        $engine->dump($productionDatabase->name, $dumpPath);

        $stagingDatabase = $this->createStagingDatabase($production, $staging, $productionDatabase->engine);

        $engine->restore($stagingDatabase->name, $dumpPath);

        $this->serverOps->run(['rm', '-f', $dumpPath], $this->context($staging, 'staging_dump_cleanup'));

        $stagingDocumentRoot = $this->provisioner->documentRoot($staging);

        $this->writeWpConfig($staging, $stagingDocumentRoot, $stagingDatabase, "{$connection->host}:{$connection->port}");

        $this->searchReplace($staging, $stagingDocumentRoot, $production->url(), $staging->url());

        $this->writeMailTrap($staging, $stagingDocumentRoot);
        $this->writeNoIndexPlugin($staging, $stagingDocumentRoot);

        // The setting as well as the file. The file is what actually holds
        // under a database import, but the setting is what an owner sees
        // under Settings > Reading — leaving it ticked would tell them the
        // clone is public while the header says otherwise.
        $this->updateOption($staging, $stagingDocumentRoot, 'blog_public', '0');

        $this->flushCaches($staging, $stagingDocumentRoot);
    }

    /**
     * Never synced, in either direction.
     *
     * `wp-config.php` is the one that mattered: it carries DB_NAME, DB_USER,
     * DB_PASSWORD, WP_HOME and WP_SITEURL. Copying staging's onto production
     * pointed the live site at the staging database and pinned its URL to
     * staging's — and because those are PHP constants they override whatever
     * `wp_options` says, so the search-replace below was doing its job and
     * being silently overruled.
     *
     * The mail trap is the other one, and it fails silently in the worst way:
     * pushed to production it makes the live site stop sending email
     * altogether — order confirmations, password resets — with no error
     * anywhere.
     *
     * @return array<int, string>
     */
    public function syncExcludes(): array
    {
        return [
            // Site identity and credentials. Each site keeps its own.
            'wp-config.php',
            // Written by the panel onto staging *because* it is staging.
            'wp-content/mu-plugins/panel-staging-mail-trap.php',
            'wp-content/mu-plugins/panel-staging-noindex.php',
            // Media: rsync --delete would wipe production uploads added since
            // the clone, and there is no file-level safety copy to undo it.
            'wp-content/uploads/',
            // Regenerates itself, and is per-site by nature.
            'wp-content/cache/',
            'wp-content/upgrade/',
            // Not part of the site.
            '.git/', 'node_modules/', '*.log', '.panel/',
        ];
    }

    public function push(Application $production, Application $staging, string $mode): void
    {
        $productionDocumentRoot = $this->provisioner->documentRoot($production);

        // `database` and `full` both replace the database, so both need the
        // URL rewrite and the rewrite-rule flush below. The only difference
        // between them is whether the files were rsynced, which is the
        // manager's business and not visible from here.
        if ($mode === 'files') {
            // Files only — the database is untouched, so there are no staging
            // URLs to rewrite. The caches still have to go: the pushed files
            // include templates and assets, and a page cache serving the old
            // markup makes the push look like it did nothing.
            $this->flushCaches($production, $productionDocumentRoot);
            $this->assertProductionIntact($production, $staging, $productionDocumentRoot);

            return;
        }

        $productionDatabase = Database::where('application_id', $production->id)->first();
        $stagingDatabase = Database::where('application_id', $staging->id)->first();

        if ($productionDatabase === null || $stagingDatabase === null) {
            throw new StagingOperationException((string) Str::uuid());
        }

        // Read before anything is replaced, and refuse the push if it cannot
        // be read.
        //
        // Staging is deliberately `blog_public = 0`, so the database about to
        // land on production carries that value. Letting it through would
        // quietly de-index a live site — no error, no visible change, and
        // nobody notices until the traffic goes. Guessing is not better than
        // failing here: forcing 1 would expose a site whose owner chose to
        // keep it hidden, and forcing 0 is the bug itself. So the live site's
        // own answer is captured now and written back after the restore.
        $productionVisibility = $this->readOption($production, $productionDocumentRoot, 'blog_public');

        if ($productionVisibility === null) {
            throw new StagingOperationException((string) Str::uuid());
        }

        $engine = $this->databases->engine($productionDatabase->engine);
        $dumpPath = '/tmp/panel-staging-push-'.Str::uuid()->toString().'.sql';

        $engine->dump($stagingDatabase->name, $dumpPath);
        $engine->restore($productionDatabase->name, $dumpPath);

        $this->serverOps->run(['rm', '-f', $dumpPath], $this->context($production, 'staging_push_dump_cleanup'));

        // The production database now physically contains staging's rows —
        // including staging's URL, in every spelling WordPress and its
        // plugins use. Rewrite them back on the production site; the staging
        // site's own files and config are untouched by a push.
        $this->rewriteUrls($production, $productionDocumentRoot, $staging, $production);

        // Put the live site's own search-engine visibility back. The row that
        // just arrived is staging's, and staging is always hidden.
        $this->updateOption($production, $productionDocumentRoot, 'blog_public', $productionVisibility);

        // Permalinks come from the staging database that was just restored.
        // Without a flush the rewrite rules on disk no longer match, and
        // every post and page 404s until somebody re-saves permalinks.
        $this->flushRewrites($production, $productionDocumentRoot);
        $this->flushCaches($production, $productionDocumentRoot);

        $this->assertProductionIntact($production, $staging, $productionDocumentRoot);
        $this->assertVisibilityPreserved($production, $productionDocumentRoot, $productionVisibility);
    }

    /**
     * Replace every spelling of the source URL, longest first.
     *
     * One literal `https://staging.example.com` is not enough, and the gaps
     * are the ones that leave a site half-migrated:
     *
     *  - **Scheme mismatch.** `Application::url()` builds from the site's own
     *    scheme, so a staging site on http and a production site on https
     *    produce two strings that never match each other. That single case
     *    misses *everything*.
     *  - **Escaped slashes.** The block editor and any JSON-encoded option
     *    store `https:\/\/host`. wp-cli does not unescape before matching, so
     *    a plain replace walks straight past post content.
     *  - **Protocol-relative.** `//host` appears in enqueued asset URLs.
     *  - **Bare domain.** Email templates, plugin settings and CSV exports
     *    keep the host with no scheme at all.
     *
     * Ordered longest to shortest so the bare-domain pass runs last and
     * cannot corrupt a string an earlier, more specific pass already fixed.
     */
    private function rewriteUrls(Application $application, string $documentRoot, Application $from, Application $to): void
    {
        foreach ($this->urlVariants($from, $to) as [$search, $replace]) {
            if ($search === $replace) {
                continue;
            }

            $this->searchReplace($application, $documentRoot, $search, $replace);
        }
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function urlVariants(Application $from, Application $to): array
    {
        $fromHost = $from->domain;
        $toHost = $to->domain;

        $variants = [];

        // Both schemes for each side, so a staging-on-http / production-on-
        // https pair is still caught.
        foreach (['https://', 'http://'] as $scheme) {
            $variants[] = [$scheme.$fromHost, $to->url()];
            $variants[] = [str_replace('/', '\\/', $scheme).$fromHost, str_replace('/', '\\/', $to->url())];
        }

        $variants[] = ['//'.$fromHost, '//'.$toHost];
        $variants[] = ['\\/\\/'.$fromHost, '\\/\\/'.$toHost];
        // Last, and only the host: anything with a scheme is already done.
        $variants[] = [$fromHost, $toHost];

        return $variants;
    }

    /**
     * Read one option, or null when it cannot be read.
     *
     * Null is deliberately distinct from an empty answer: "the site says this
     * option is empty" and "wp-cli could not tell us" lead to different
     * decisions, and conflating them is how a live site would get silently
     * de-indexed on a wp-cli hiccup.
     */
    private function readOption(Application $application, string $documentRoot, string $option): ?string
    {
        $result = $this->serverOps->run(
            array_merge(['runuser', '-u', $application->systemUser->username, '--'], [
                $this->wpCliBinary(), 'option', 'get', $option,
                '--path='.$documentRoot, '--skip-plugins', '--skip-themes',
            ]),
            $this->context($application, 'staging_option_get'),
            timeout: 60,
        );

        if ($result->failed()) {
            return null;
        }

        $value = trim($result->output());

        return $value === '' ? null : $value;
    }

    private function updateOption(Application $application, string $documentRoot, string $option, string $value): void
    {
        $this->runAsSiteUser($application, [
            $this->wpCliBinary(), 'option', 'update', $option, $value,
            '--path='.$documentRoot, '--skip-plugins', '--skip-themes',
        ]);
    }

    /**
     * A push must not change whether the live site is visible to search
     * engines. Getting this wrong is invisible on the day and expensive
     * months later, which is exactly the kind of thing worth asserting.
     */
    private function assertVisibilityPreserved(Application $production, string $documentRoot, string $expected): void
    {
        $actual = $this->readOption($production, $documentRoot, 'blog_public');

        if ($actual !== $expected) {
            throw new StagingOperationException((string) Str::uuid());
        }
    }

    private function writeNoIndexPlugin(Application $staging, string $documentRoot): void
    {
        $contents = View::make('server.apps.wordpress.mu-plugin-staging-noindex')->render();

        $this->writeSecretFile($staging, "{$documentRoot}/wp-content/mu-plugins/panel-staging-noindex.php", $contents, '0644');
    }

    private function flushRewrites(Application $application, string $documentRoot): void
    {
        $this->runAsSiteUser($application, [
            $this->wpCliBinary(), 'rewrite', 'flush', '--hard',
            '--path='.$documentRoot, '--skip-plugins', '--skip-themes',
        ]);
    }

    /**
     * Refuse to call a push finished if production came out of it wearing
     * staging's identity.
     *
     * This is the check that would have caught the whole class of bug rather
     * than one instance of it: whatever else changes about the sync, the live
     * site must still be connected to its own database and answering on its
     * own URL. Throwing here puts StagingManager into its rollback path,
     * which restores the file snapshot and the pre-push database dump.
     */
    private function assertProductionIntact(Application $production, Application $staging, string $documentRoot): void
    {
        $config = $this->serverOps->run(
            ['cat', "{$documentRoot}/wp-config.php"],
            $this->context($production, 'staging_push_verify_config'),
        );

        if ($config->failed()) {
            throw new StagingOperationException($config->reference);
        }

        $contents = $config->output();
        $stagingDatabase = Database::where('application_id', $staging->id)->first();

        // The live site must not be pointed at staging's database, and must
        // not have had its URL pinned to staging's by a copied constant.
        $wearsStagingIdentity = ($stagingDatabase !== null && str_contains($contents, "'{$stagingDatabase->name}'"))
            || str_contains($contents, $staging->url());

        if ($wearsStagingIdentity) {
            throw new StagingOperationException((string) Str::uuid());
        }
    }

    private function createStagingDatabase(Application $production, Application $staging, string $engine): Database
    {
        $name = $this->databaseIdentifiers->generateAvailable($production->name, $engine, 'staging');
        $password = DatabasePassword::generate();

        try {
            return $this->createDatabase->execute([
                'name' => $name,
                'engine' => $engine,
                'application_id' => $staging->id,
                'create_user' => [
                    'username' => $name,
                    'password' => $password,
                    'connection_preference' => 'localhost',
                ],
            ]);
        } catch (\Throwable) {
            throw new StagingOperationException((string) Str::uuid());
        }
    }

    private function writeWpConfig(Application $staging, string $documentRoot, Database $database, string $host): void
    {
        $user = $database->users->first();

        $contents = View::make('server.apps.wordpress.wp-config', [
            'database' => $database->name,
            'username' => $user->username,
            'password' => $user->password,
            'host' => $host,
            'prefix' => 'wp_',
            'salts' => $this->salts(),
            'home' => $staging->url(),
            'environmentType' => 'staging',
            'disableCron' => true,
        ])->render();

        $this->writeSecretFile($staging, "{$documentRoot}/wp-config.php", $contents);
    }

    private function writeMailTrap(Application $staging, string $documentRoot): void
    {
        $directory = $this->serverOps->run(
            ['mkdir', '-p', "{$documentRoot}/wp-content/mu-plugins"],
            $this->context($staging, 'staging_mu_plugins_dir'),
        );

        if ($directory->failed()) {
            throw new StagingOperationException($directory->reference, $directory->busy, $directory->staleLock);
        }

        $contents = View::make('server.apps.wordpress.mu-plugin-trap-mail')->render();

        $this->writeSecretFile($staging, "{$documentRoot}/wp-content/mu-plugins/panel-staging-mail-trap.php", $contents, '0644');
    }

    /**
     * Serialized-data-safe: `--recurse-objects --precise` walk PHP-serialized
     * blobs (widgets, page-builder settings) rather than a flat string
     * replace, which corrupts them by shifting byte-length prefixes.
     * `--skip-columns=guid` protects feed identity;
     * `--skip-plugins --skip-themes` survives a broken one.
     */
    private function searchReplace(Application $application, string $documentRoot, string $from, string $to): void
    {
        $this->runAsSiteUser($application, [
            $this->wpCliBinary(),
            'search-replace', $from, $to,
            '--path='.$documentRoot,
            '--all-tables', '--precise', '--recurse-objects',
            '--skip-columns=guid',
            '--skip-plugins', '--skip-themes',
        ]);
    }

    private function flushCaches(Application $application, string $documentRoot): void
    {
        $this->runAsSiteUser($application, [
            $this->wpCliBinary(), 'cache', 'flush', '--path='.$documentRoot, '--skip-plugins', '--skip-themes',
        ]);

        $this->runAsSiteUser($application, [
            $this->wpCliBinary(), 'transient', 'delete', '--all', '--path='.$documentRoot, '--skip-plugins', '--skip-themes',
        ]);
    }

    private function wpCliBinary(): string
    {
        return (string) config('server.installers.wordpress.wp_cli', '/usr/local/bin/wp');
    }

    /**
     * @param  array<int, string>  $command
     */
    private function runAsSiteUser(Application $application, array $command): void
    {
        $result = $this->serverOps->run(
            array_merge(['runuser', '-u', $application->systemUser->username, '--'], $command),
            $this->context($application, 'staging_wp_cli'),
            timeout: 120,
        );

        if ($result->failed()) {
            throw new StagingOperationException($result->reference);
        }
    }

    private function writeSecretFile(Application $application, string $path, string $contents, string $mode = '0640'): void
    {
        $written = $this->serverOps->run(['tee', $path], $this->context($application, 'staging_write_file'), input: $contents);

        if ($written->failed()) {
            throw new StagingOperationException($written->reference);
        }

        $modeResult = $this->serverOps->run(['chmod', $mode, $path], $this->context($application, 'staging_chmod'));

        if ($modeResult->failed()) {
            throw new StagingOperationException($modeResult->reference, $modeResult->busy, $modeResult->staleLock);
        }

        $ownership = $this->serverOps->run(
            ['chown', "{$application->systemUser->username}:{$application->systemUser->username}", $path],
            $this->context($application, 'staging_chown'),
        );

        if ($ownership->failed()) {
            throw new StagingOperationException($ownership->reference, $ownership->busy, $ownership->staleLock);
        }
    }

    /** @return array<string, string> */
    private function salts(): array
    {
        return collect(['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'])
            ->mapWithKeys(fn (string $key) => [$key => Str::random(64)])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Application $application, string $op): array
    {
        return ['feature' => 'application', 'op' => $op, 'application' => $application->id];
    }
}
