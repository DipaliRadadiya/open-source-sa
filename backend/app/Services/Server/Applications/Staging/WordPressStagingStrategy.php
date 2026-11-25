<?php

namespace App\Services\Server\Applications\Staging;

use App\Actions\Server\Database\CreateDatabase;
use App\Contracts\StagingStrategy;
use App\Exceptions\Server\Application\StagingOperationException;
use App\Models\Application;
use App\Models\Database;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\WordPressTablePrefix;
use App\Services\Server\Applications\WordPressUrlVariants;
use App\Services\Server\Databases\DatabaseIdentifier;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\DatabasePassword;
use App\Services\Server\Php\RuntimeOwnership;
use App\Services\Server\ServerOps;
use App\Services\Server\WpCli\WpCli;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * The database half of WordPress staging: clone it, point wp-config.php at the copy, rewrite the
 * URLs in a serialized-data-safe way, and trap outbound mail. Files are already copied and the
 * staging site provisioned by the time `StagingManager` calls in; see the `StagingStrategy` contract.
 *
 * `--skip-plugins --skip-themes` on every `wp` call: a broken plugin or theme on production must
 * not also break staging.
 */
class WordPressStagingStrategy implements StagingStrategy
{
    public function __construct(
        private ApplicationProvisioner $provisioner,
        private DatabaseManager $databases,
        private DatabaseIdentifier $databaseIdentifiers,
        private CreateDatabase $createDatabase,
        private ServerOps $serverOps,
        private WordPressTablePrefix $tablePrefix,
    ) {}

    public function create(Application $production, Application $staging): void
    {
        $productionDatabase = Database::where('application_id', $production->id)->with('users')->first();

        if ($productionDatabase === null) {
            // Should not happen (WordPress::needsDatabase() is true); refuse rather than silently
            // skip the database half.
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

        $this->writeWpConfig($staging, $this->tablePrefix->of($production), $stagingDocumentRoot, $stagingDatabase, "{$connection->host}:{$connection->port}");

        // Every spelling, not just the one URL: a link saved as http:// or in the block editor's
        // escaped form would otherwise still point at the live site.
        $this->rewriteUrls($staging, $stagingDocumentRoot, $production, $staging);

        $this->writeMailTrap($staging, $stagingDocumentRoot);
        $this->writeNoIndexPlugin($staging, $stagingDocumentRoot);

        // The setting as well as the file: the owner sees it under Settings > Reading.
        $this->updateOption($staging, $stagingDocumentRoot, 'blog_public', '0');

        $this->flushCaches($staging, $stagingDocumentRoot);
    }

    /**
     * Never synced, in either direction.
     *
     * `wp-config.php` carries DB_NAME, DB_USER, DB_PASSWORD, WP_HOME and WP_SITEURL. Copying
     * staging's onto production would point the live site at the staging database, and those PHP
     * constants override `wp_options`, so the search-replace would be silently overruled.
     *
     * The mail trap must not reach production either: the live site would stop sending email
     * (order confirmations, password resets) with no error anywhere.
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
            // Media: kept out of *this* pass because it carries --delete and a photo uploaded to
            // production meanwhile must survive a push. `mergePaths()` copies it instead.
            'wp-content/uploads/',
            // Regenerates itself, and is per-site by nature.
            'wp-content/cache/',
            'wp-content/upgrade/',
            // Not part of the site.
            '.git/', 'node_modules/', '*.log', '.panel/',
        ];
    }

    /**
     * Media, and only media.
     *
     * An upload is a `wp_posts` row plus a file under `wp-content/uploads`; the row crosses with
     * the database, so leaving the file behind makes the Media Library list an image that 404s.
     *
     * Additive, not mirrored: nothing is removed from the destination, so a push cannot destroy
     * production media it has no copy of (at the cost that deleting a file in staging leaves it
     * on production). Cache and upgrade directories are not here: they regenerate.
     *
     * @return array<int, string>
     */
    public function mergePaths(): array
    {
        return ['wp-content/uploads/'];
    }

    public function push(Application $production, Application $staging, string $mode): void
    {
        $productionDocumentRoot = $this->provisioner->documentRoot($production);

        // `database` and `full` both replace the database, so both need the URL rewrite and the
        // rewrite-rule flush below; whether files were rsynced is the manager's business.
        if ($mode === 'files') {
            // Files only: no staging URLs to rewrite, but the caches must go or a page cache
            // serving old markup makes the push look like it did nothing.
            $this->flushCaches($production, $productionDocumentRoot);
            $this->assertProductionIntact($production, $staging, $productionDocumentRoot);

            return;
        }

        $productionDatabase = Database::where('application_id', $production->id)->first();
        $stagingDatabase = Database::where('application_id', $staging->id)->first();

        if ($productionDatabase === null || $stagingDatabase === null) {
            throw new StagingOperationException((string) Str::uuid());
        }

        // Read before anything is replaced; refuse the push if it cannot be read. Staging is
        // `blog_public = 0`, so the incoming database would silently de-index the live site, and
        // guessing is wrong either way (forcing 1 may expose a hidden site). The live value is
        // captured now and written back after the restore.
        $productionVisibility = $this->readOption($production, $productionDocumentRoot, 'blog_public');

        if ($productionVisibility === null) {
            throw new StagingOperationException((string) Str::uuid());
        }

        $engine = $this->databases->engine($productionDatabase->engine);
        $dumpPath = '/tmp/panel-staging-push-'.Str::uuid()->toString().'.sql';

        $engine->dump($stagingDatabase->name, $dumpPath);
        $engine->restore($productionDatabase->name, $dumpPath);

        $this->serverOps->run(['rm', '-f', $dumpPath], $this->context($production, 'staging_push_dump_cleanup'));

        // The production database now holds staging's URL in every spelling; rewrite them back.
        $this->rewriteUrls($production, $productionDocumentRoot, $staging, $production);

        // Put the live site's own search-engine visibility back (staging's row is always hidden).
        $this->updateOption($production, $productionDocumentRoot, 'blog_public', $productionVisibility);

        // Without a flush the rewrite rules on disk no longer match the restored permalinks and
        // every post and page 404s.
        $this->flushRewrites($production, $productionDocumentRoot);
        $this->flushCaches($production, $productionDocumentRoot);

        $this->assertProductionIntact($production, $staging, $productionDocumentRoot);
        $this->assertVisibilityPreserved($production, $productionDocumentRoot, $productionVisibility);
    }

    /**
     * Update staging from live. Staging's wp-config never moved (`syncExcludes()`), so it keeps its
     * own database, URL and DISABLE_WP_CRON; this copies live's rows in, points every URL at
     * staging, and re-asserts what makes staging safe: hidden from search engines and unable to
     * email live customers (live's rows carry its `blog_public` and none of the mu-plugins).
     */
    public function pull(Application $production, Application $staging, string $mode): void
    {
        $stagingDocumentRoot = $this->provisioner->documentRoot($staging);

        if ($mode !== 'files') {
            $productionDatabase = Database::where('application_id', $production->id)->first();
            $stagingDatabase = Database::where('application_id', $staging->id)->first();

            if ($productionDatabase === null || $stagingDatabase === null) {
                throw new StagingOperationException((string) Str::uuid());
            }

            $engine = $this->databases->engine($productionDatabase->engine);
            $dumpPath = '/tmp/panel-staging-pull-'.Str::uuid()->toString().'.sql';

            $engine->dump($productionDatabase->name, $dumpPath);
            $engine->restore($stagingDatabase->name, $dumpPath);

            $this->serverOps->run(['rm', '-f', $dumpPath], $this->context($staging, 'staging_pull_dump_cleanup'));

            $this->rewriteUrls($staging, $stagingDocumentRoot, $production, $staging);
            $this->updateOption($staging, $stagingDocumentRoot, 'blog_public', '0');
            $this->flushRewrites($staging, $stagingDocumentRoot);
        }

        $this->writeMailTrap($staging, $stagingDocumentRoot);
        $this->writeNoIndexPlugin($staging, $stagingDocumentRoot);
        $this->flushCaches($staging, $stagingDocumentRoot);

        $this->assertStagingIntact($production, $staging, $stagingDocumentRoot);

        if ($mode !== 'files') {
            $this->assertVisibilityPreserved($staging, $stagingDocumentRoot, '0');
        }
    }

    /**
     * The mirror of `assertProductionIntact()`: a pull must never leave staging connected to
     * live's database or pinned to live's URL (a staging copy that writes to the live shop).
     */
    private function assertStagingIntact(Application $production, Application $staging, string $documentRoot): void
    {
        $config = $this->serverOps->run(
            ['cat', "{$documentRoot}/wp-config.php"],
            $this->context($staging, 'staging_pull_verify_config'),
        );

        if ($config->failed()) {
            throw new StagingOperationException($config->reference);
        }

        $contents = $config->output();
        $productionDatabase = Database::where('application_id', $production->id)->first();

        if (($productionDatabase !== null && str_contains($contents, "'{$productionDatabase->name}'"))
            // Quoted, as wp-config writes it: a staging domain built on live's contains live's URL
            // as a prefix.
            || str_contains($contents, "'".$production->url()."'")) {
            throw new StagingOperationException((string) Str::uuid());
        }
    }

    /**
     * Replace every spelling of the source URL — see WordPressUrlVariants.
     */
    private function rewriteUrls(Application $application, string $documentRoot, Application $from, Application $to): void
    {
        foreach (WordPressUrlVariants::between($from, $to) as $variant) {
            [$search, $replace] = $variant;

            if ($search === $replace) {
                continue;
            }

            $this->searchReplace($application, $documentRoot, $search, $replace, $variant[2] ?? false);
        }
    }

    /**
     * Read one option, or null when it cannot be read.
     *
     * Null is distinct from an empty answer: "the option is empty" and "wp-cli could not tell us"
     * must not be conflated, or a wp-cli hiccup could silently de-index a live site.
     */
    private function readOption(Application $application, string $documentRoot, string $option): ?string
    {
        $result = $this->serverOps->run(
            array_merge(['runuser', '-u', $application->systemUser->username, '--'], [
                ...$this->wpCli($application), 'option', 'get', $option,
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
            ...$this->wpCli($application), 'option', 'update', $option, $value,
            '--path='.$documentRoot, '--skip-plugins', '--skip-themes',
        ]);
    }

    /**
     * A push must not change whether the live site is visible to search engines; getting it
     * wrong is invisible on the day and expensive months later.
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
            ...$this->wpCli($application), 'rewrite', 'flush', '--hard',
            '--path='.$documentRoot, '--skip-plugins', '--skip-themes',
        ]);
    }

    /**
     * Refuse to call a push finished if production came out of it wearing staging's identity: the
     * live site must still use its own database and URL. Throwing here puts StagingManager into
     * its rollback path (file snapshot and pre-push database dump).
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

        // Not pointed at staging's database, nor its URL pinned to staging's by a copied constant.
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

    private function writeWpConfig(Application $staging, string $prefix, string $documentRoot, Database $database, string $host): void
    {
        $user = $database->users->first();

        $contents = View::make('server.apps.wordpress.wp-config', [
            'database' => $database->name,
            'username' => $user->username,
            'password' => $user->password,
            'host' => $host,
            // The source's own prefix: the tables were copied as they are.
            'prefix' => $prefix,
            'salts' => $this->salts(),
            'home' => $staging->url(),
            'environmentType' => 'staging',
            'disableCron' => true,
        ])->render();

        $this->writeSecretFile($staging, "{$documentRoot}/wp-config.php", $contents);
    }

    private function writeMailTrap(Application $staging, string $documentRoot): void
    {
        // As the user, like the file written into it: root would leave a directory the user cannot
        // enter and would follow a `wp-content` that is a link.
        $directory = $this->serverOps->run(
            ['runuser', '-u', $staging->systemUser->username, '--', 'mkdir', '-p', "{$documentRoot}/wp-content/mu-plugins"],
            $this->context($staging, 'staging_mu_plugins_dir'),
        );

        if ($directory->failed()) {
            throw new StagingOperationException($directory->reference, $directory->busy, $directory->staleLock);
        }

        $contents = View::make('server.apps.wordpress.mu-plugin-trap-mail')->render();

        $this->writeSecretFile($staging, "{$documentRoot}/wp-content/mu-plugins/panel-staging-mail-trap.php", $contents, '0644');
    }

    /**
     * Serialized-data-safe: `--recurse-objects --precise` walk PHP-serialized blobs, where a flat
     * replace would corrupt byte-length prefixes. `--skip-columns=guid` protects feed identity.
     */
    private function searchReplace(Application $application, string $documentRoot, string $from, string $to, bool $regex = false): void
    {
        $this->runAsSiteUser($application, [
            ...$this->wpCli($application),
            'search-replace', $from, $to,
            '--path='.$documentRoot,
            '--all-tables', '--precise', '--recurse-objects',
            '--skip-columns=guid',
            '--skip-plugins', '--skip-themes',
            ...($regex ? ['--regex', '--regex-delimiter='.WordPressUrlVariants::REGEX_DELIMITER] : []),
        ]);
    }

    private function flushCaches(Application $application, string $documentRoot): void
    {
        $this->runAsSiteUser($application, [
            ...$this->wpCli($application), 'cache', 'flush', '--path='.$documentRoot, '--skip-plugins', '--skip-themes',
        ]);

        $this->runAsSiteUser($application, [
            ...$this->wpCli($application), 'transient', 'delete', '--all', '--path='.$documentRoot, '--skip-plugins', '--skip-themes',
        ]);
    }

    /** @return list<string> wp-cli on the site's own PHP. */
    private function wpCli(Application $application): array
    {
        return app(WpCli::class)->command($application);
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

    private function writeSecretFile(Application $application, string $path, string $contents, ?string $mode = null): void
    {
        $mode ??= app(RuntimeOwnership::class)->secretFileMode($application);

        // As the site's own user: the directory is a copy of another site, so a planted link
        // (`wp-config.php`, `mu-plugins`) came across with it, and root's `tee` + `chown` would
        // write through it and hand the target to the user. The copy is already theirs (rsync()
        // chowns it), so a link reaches only what they could already write.
        $user = $application->systemUser->username;

        $written = $this->serverOps->run(['runuser', '-u', $user, '--', 'tee', $path], $this->context($application, 'staging_write_file'), input: $contents);

        if ($written->failed()) {
            throw new StagingOperationException($written->reference);
        }

        $modeResult = $this->serverOps->run(['runuser', '-u', $user, '--', 'chmod', $mode, $path], $this->context($application, 'staging_chmod'));

        if ($modeResult->failed()) {
            throw new StagingOperationException($modeResult->reference, $modeResult->busy, $modeResult->staleLock);
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
