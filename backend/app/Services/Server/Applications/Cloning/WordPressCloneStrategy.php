<?php

namespace App\Services\Server\Applications\Cloning;

use App\Actions\Server\Database\CreateDatabase;
use App\Contracts\CloneStrategy;
use App\Exceptions\Server\Application\CloneOperationException;
use App\Models\Application;
use App\Models\Database;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\WordPressUrlVariants;
use App\Services\Server\Databases\DatabaseIdentifier;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\DatabasePassword;
use App\Services\Server\Php\RuntimeOwnership;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Throwable;

/**
 * The database half of a WordPress clone: give it its own database (a copy
 * of the source's data), point wp-config.php at it, and rewrite the URLs a
 * serialized-data-safe way — the exact same problem `WordPressStagingStrategy`
 * solves, minus every staging-only safeguard (no `DISABLE_WP_CRON`, no mail
 * trap, no `WP_ENVIRONMENT_TYPE`). A clone is meant to become its own real,
 * independent site, not a safe sandbox — treating it like one would leave a
 * "clone of my shop" quietly unable to send order emails until someone
 * noticed and asked why.
 */
class WordPressCloneStrategy implements CloneStrategy
{
    public function __construct(
        private ApplicationProvisioner $provisioner,
        private DatabaseManager $databases,
        private DatabaseIdentifier $databaseIdentifiers,
        private ServerOps $serverOps,
        private CreateDatabase $createDatabase,
    ) {}

    public function clone(Application $source, Application $clone): void
    {
        $sourceDatabase = Database::where('application_id', $source->id)->with('users')->first();

        if ($sourceDatabase === null) {
            throw new CloneOperationException((string) Str::uuid());
        }

        $engine = $this->databases->engine($sourceDatabase->engine);
        $connection = $this->databases->connection($sourceDatabase->engine);
        $dumpPath = '/tmp/panel-clone-'.Str::uuid()->toString().'.sql';

        $engine->dump($sourceDatabase->name, $dumpPath);

        $cloneDatabase = $this->createCloneDatabase($source, $clone, $sourceDatabase->engine);

        $engine->restore($cloneDatabase->name, $dumpPath);

        $this->serverOps->run(['rm', '-f', $dumpPath], $this->context($clone, 'clone_dump_cleanup'));

        $documentRoot = $this->provisioner->documentRoot($clone);

        $this->writeWpConfig($clone, $documentRoot, $cloneDatabase, "{$connection->host}:{$connection->port}");

        // Every spelling of the source's address, not just its current URL
        // (bug #92): a link saved as http://, in the block editor's escaped
        // form or as a bare domain otherwise still pointed at the original.
        foreach (WordPressUrlVariants::between($source, $clone) as $variant) {
            [$search, $replace] = $variant;

            if ($search !== $replace) {
                $this->searchReplace($clone, $documentRoot, $search, $replace, $variant[2] ?? false);
            }
        }
    }

    private function createCloneDatabase(Application $source, Application $clone, string $engine): Database
    {
        $name = $this->databaseIdentifiers->generateAvailable($source->name, $engine, 'clone');
        $password = DatabasePassword::generate();

        try {
            return $this->createDatabase->execute([
                'name' => $name,
                'engine' => $engine,
                'application_id' => $clone->id,
                'create_user' => [
                    'username' => $name,
                    'password' => $password,
                    'connection_preference' => 'localhost',
                ],
            ]);
        } catch (Throwable) {
            throw new CloneOperationException((string) Str::uuid());
        }
    }

    private function writeWpConfig(Application $clone, string $documentRoot, Database $database, string $host): void
    {
        $user = $database->users->first();

        $contents = View::make('server.apps.wordpress.wp-config', [
            'database' => $database->name,
            'username' => $user->username,
            'password' => $user->password,
            'host' => $host,
            'prefix' => 'wp_',
            'salts' => $this->salts(),
            // No `home`/`environmentType`/`disableCron` — a clone is a real
            // independent site, not a staging sandbox.
        ])->render();

        $this->writeSecretFile($clone, "{$documentRoot}/wp-config.php", $contents);
    }

    private function searchReplace(Application $application, string $documentRoot, string $from, string $to, bool $regex = false): void
    {
        $result = $this->serverOps->run(
            array_merge(
                ['runuser', '-u', $application->systemUser->username, '--'],
                [
                    $this->wpCliBinary(),
                    'search-replace', $from, $to,
                    '--path='.$documentRoot,
                    '--all-tables', '--precise', '--recurse-objects',
                    '--skip-columns=guid',
                    '--skip-plugins', '--skip-themes',
                    ...($regex ? ['--regex', '--regex-delimiter='.WordPressUrlVariants::REGEX_DELIMITER] : []),
                ],
            ),
            $this->context($application, 'clone_wp_cli'),
            timeout: 120,
        );

        if ($result->failed()) {
            throw new CloneOperationException($result->reference);
        }
    }

    private function wpCliBinary(): string
    {
        return (string) config('server.installers.wordpress.wp_cli', '/usr/local/bin/wp');
    }

    private function writeSecretFile(Application $application, string $path, string $contents): void
    {
        // As the site's own user. The directory is a copy of another site —
        // anything its owner planted there came across with it, including a
        // `wp-config.php` or `mu-plugins` that is a link — and this ran as
        // root: `tee` wrote through the link and the `chown` after it handed
        // the target to the user (the same class of bug found in `.panel`,
        // 2026-09-29). The copy is already theirs by now (rsync() chowns it),
        // so as the user the file is theirs by construction and a link
        // reaches only what they could already write.
        $user = $application->systemUser->username;

        $written = $this->serverOps->run(['runuser', '-u', $user, '--', 'tee', $path], $this->context($application, 'clone_write_file'), input: $contents);

        if ($written->failed()) {
            throw new CloneOperationException($written->reference);
        }

        $modeResult = $this->serverOps->run(['runuser', '-u', $user, '--', 'chmod', app(RuntimeOwnership::class)->secretFileMode($application), $path], $this->context($application, 'clone_chmod'));

        if ($modeResult->failed()) {
            throw new CloneOperationException($modeResult->reference, $modeResult->busy, $modeResult->staleLock);
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
