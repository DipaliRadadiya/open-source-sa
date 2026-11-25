<?php

namespace App\Services\Server\Applications\Cloning;

use App\Contracts\CloneStrategy;
use App\Exceptions\Server\Application\CloneOperationException;
use App\Models\Application;
use App\Models\Database;
use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\WordPressTablePrefix;
use App\Services\Server\Applications\WordPressUrlVariants;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Php\RuntimeOwnership;
use App\Services\Server\ServerOps;
use App\Services\Server\WpCli\WpCli;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * The database half of a WordPress clone: give it its own database (a copy of the source's
 * data), point wp-config.php at it, and rewrite the URLs serialized-data-safe, as
 * `WordPressStagingStrategy` does, minus the staging-only safeguards (`DISABLE_WP_CRON`, mail
 * trap, `WP_ENVIRONMENT_TYPE`). A clone becomes a real independent site, not a sandbox.
 */
class WordPressCloneStrategy implements CloneStrategy
{
    public function __construct(
        private ApplicationProvisioner $provisioner,
        private DatabaseManager $databases,
        private DatabaseCopy $databaseCopy,
        private ServerOps $serverOps,
        private WordPressTablePrefix $tablePrefix,
    ) {}

    public function clone(Application $source, Application $clone): void
    {
        $sourceDatabase = Database::where('application_id', $source->id)->with('users')->first();

        if ($sourceDatabase === null) {
            throw new CloneOperationException((string) Str::uuid());
        }

        $connection = $this->databases->connection($sourceDatabase->engine);
        $cloneDatabase = $this->databaseCopy->copy($sourceDatabase, $source, $clone);

        $documentRoot = $this->provisioner->documentRoot($clone);

        $this->writeWpConfig($clone, $this->tablePrefix->of($source), $documentRoot, $cloneDatabase, "{$connection->host}:{$connection->port}");

        // Every spelling of the source's address, not just its current URL: http://, the block
        // editor's escaped form and a bare domain would otherwise still point at the original.
        foreach (WordPressUrlVariants::between($source, $clone) as $variant) {
            [$search, $replace] = $variant;

            if ($search !== $replace) {
                $this->searchReplace($clone, $documentRoot, $search, $replace, $variant[2] ?? false);
            }
        }
    }

    private function writeWpConfig(Application $clone, string $prefix, string $documentRoot, Database $database, string $host): void
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
                    ...$this->wpCli($application),
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

    /** @return list<string> wp-cli on the site's own PHP. */
    private function wpCli(Application $application): array
    {
        return app(WpCli::class)->command($application);
    }

    private function writeSecretFile(Application $application, string $path, string $contents): void
    {
        // As the site's own user, never root: the copied directory may hold a `wp-config.php` or
        // `mu-plugins` that is a symlink, and root writing through it would hand the target to
        // the user. The copy is already theirs (rsync() chowns it), so a link reaches only what
        // they could already write.
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
