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

        $this->flushCaches($staging, $stagingDocumentRoot);
    }

    public function push(Application $production, Application $staging, string $mode): void
    {
        if ($mode !== 'full') {
            // `files` already happened in StagingManager — nothing in this
            // recipe is file-only, so there is nothing left to do here.
            return;
        }

        $productionDatabase = Database::where('application_id', $production->id)->first();
        $stagingDatabase = Database::where('application_id', $staging->id)->first();

        if ($productionDatabase === null || $stagingDatabase === null) {
            throw new StagingOperationException((string) Str::uuid());
        }

        $engine = $this->databases->engine($productionDatabase->engine);
        $dumpPath = '/tmp/panel-staging-push-'.Str::uuid()->toString().'.sql';

        $engine->dump($stagingDatabase->name, $dumpPath);
        $engine->restore($productionDatabase->name, $dumpPath);

        $this->serverOps->run(['rm', '-f', $dumpPath], $this->context($production, 'staging_push_dump_cleanup'));

        // The production database now physically contains staging's rows —
        // including staging's URL. Rewrite it back on the production site,
        // not the staging one; the staging site's own files/config are
        // untouched by a push.
        $productionDocumentRoot = $this->provisioner->documentRoot($production);
        $this->searchReplace($production, $productionDocumentRoot, $staging->url(), $production->url());
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
