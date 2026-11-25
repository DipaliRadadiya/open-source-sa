<?php

namespace App\Services\Server\Applications;

use App\Actions\Server\Database\CreateDatabase;
use App\Actions\Server\Database\CreateDatabaseUser;
use App\Contracts\SiteInstaller;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Exceptions\Server\ServerOperationException;
use App\Models\Application;
use App\Models\Database;
use App\Services\Recipes\RecipeRegistry;
use App\Services\Server\Applications\Installers\DockerAppInstaller;
use App\Services\Server\Databases\DatabaseIdentifier;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\DatabasePassword;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the marketplace installer for an application, creating its database
 * first when the app needs one.
 *
 * Site types with no installer (git, blank PHP, static) pass straight through.
 */
class InstallerManager
{
    public function __construct(
        private CreateDatabase $createDatabase,
        private CreateDatabaseUser $createUser,
        private DatabaseManager $databases,
        private DatabaseIdentifier $databaseIdentifiers,
        private ProvisionProgress $progress,
        private EngineVersionSupport $versions,
    ) {}

    /**
     * Whether a site type installs anything, **without building the installer**.
     *
     * `installerForType()` resolves the class, and a PHP installer asks the container for the
     * `PhpStack`, which shells out to detect one when there is no capability record yet. Fine in
     * a queued job, wrong in an HTTP request that only wants to know whether an installer exists.
     */
    public function hasInstaller(string $siteType): bool
    {
        return config("server.installers.{$siteType}.driver") !== null
            || app(RecipeRegistry::class)->has($siteType);
    }

    public function installerFor(Application $application): ?SiteInstaller
    {
        return $this->installerForType((string) $application->site_type);
    }

    /**
     * The installer for a site type, by name — so the catalog can say whether
     * a card installs anything without inventing an Application to ask with.
     */
    public function installerForType(string $siteType): ?SiteInstaller
    {
        $class = config("server.installers.{$siteType}.driver");

        if ($class !== null) {
            return app($class);
        }

        return app(RecipeRegistry::class)->has($siteType) ? app(DockerAppInstaller::class) : null;
    }

    /**
     * Steps are reported to {@see ProvisionProgress} as they complete rather
     * than returned, so a site the user is watching install shows progress
     * while it happens.
     *
     * @throws ProvisioningFailedException
     */
    public function install(Application $application, string $documentRoot): void
    {
        $installer = $this->installerFor($application);

        if ($installer === null) {
            return;
        }

        $context = [];

        if ($installer->needsDatabase()) {
            $context = $this->provisionDatabase(
                $application,
                $installer->acceptedEngines(),
                $installer->minimumEngineVersions(),
            );
        }

        $installer->install($application, $documentRoot, $context);
    }

    /**
     * The installer's post-start step. {@see SiteInstaller::afterStart()}
     *
     * @throws ProvisioningFailedException
     */
    public function afterStart(Application $application, string $documentRoot): void
    {
        $this->installerFor($application)?->afterStart($application, $documentRoot);
    }

    /**
     * Bring an installed application's own canonical URL in line with the
     * domain and certificate the panel currently serves.
     *
     * An explicit target is used while transitioning a certificate: before it
     * is marked active Application::url() still correctly reports HTTP.
     *
     * @throws ProvisioningFailedException
     */
    public function syncUrl(Application $application, ?string $target = null): void
    {
        $installer = $this->installerFor($application);

        if ($installer === null) {
            return;
        }

        $application->loadMissing(['systemUser', 'certificate']);
        $installer->syncUrl($application, $target ?? $application->url());
    }

    /**
     * Create the application's database and a dedicated user for it.
     *
     * The password is generated, never asked for: one fewer weak secret, and it only ever goes
     * into the app's own config file.
     *
     * @param  array<int, string>  $accepted  engines this application can use
     * @param  array<string, string>  $minimums  its minimum version per engine
     * @return array<string, mixed>
     *
     * @throws ProvisioningFailedException
     */
    private function provisionDatabase(Application $application, array $accepted, array $minimums = []): array
    {
        // Retry Setup must not mint a second database: an application has at most one by design
        // (see UpdateDatabaseApplicationRequest), so "it already has one" is a complete answer.
        if (($attached = $application->databases()->with('users')->first()) !== null) {
            return $this->reuseDatabase($application, $attached, $accepted);
        }

        // An engine the user chose was already held to the minimum by StoreApplicationRequest;
        // only the fallback, where the panel picks, consults the version.
        $engine = $this->chosenEngine($application, $accepted)
            ?? $this->firstAvailableEngine($accepted, $minimums);

        if ($engine === null) {
            // Fail here rather than half-installing, and say it is the database engine that is missing.
            throw new ProvisioningFailedException('create_database', 'no-database-engine');
        }

        $password = DatabasePassword::generate();

        try {
            // Named from the slug, not the domain: dots in a hostname became underscores and a
            // long host was truncated mid-word for MySQL's 32-character account limit. Rows
            // predating the slug column fall back to the domain.
            //
            // `generateAvailable`, not `generate`: it checks the name is free on the panel's rows
            // and the engine, so a collision does not surface as an opaque create_database failure.
            $name = $this->databaseIdentifiers->generateAvailable(
                $application->slug ?: $application->domain,
                $engine,
            );

            $database = $this->createDatabase->execute([
                'name' => $name,
                'engine' => $engine,
                'application_id' => $application->id,
                'create_user' => [
                    'username' => $name,
                    'password' => $password,
                    'connection_preference' => 'localhost',
                ],
            ]);
        } catch (Throwable $e) {
            throw $this->databaseFailure($application, $engine, $e);
        }

        $this->progress->record('create_database');

        return $this->connectionContext($engine, $database->name, $name, $password);
    }

    /**
     * Hand the installer the database this application already has.
     *
     * Reachable after a retry past the database step, and for an application that had one
     * attached deliberately. Both install against what is there.
     *
     * @param  array<int, string>  $accepted
     * @return array<string, mixed>
     *
     * @throws ProvisioningFailedException
     */
    private function reuseDatabase(Application $application, Database $database, array $accepted): array
    {
        // An engine this site type cannot speak is not worked around: a second database is the
        // bug avoided here, and an engine with no driver fails later, in the application's words.
        if (! in_array($database->engine, $accepted, true)) {
            throw new ProvisioningFailedException(
                'create_database',
                // A sentinel rather than a uuid, as `no-database-engine` above: nothing shelled
                // out, so no server-ops entry exists for a reference to point at.
                'attached-database-engine-mismatch',
                'attached_database_engine_mismatch',
            );
        }

        try {
            // A database attached through `PUT /databases/{database}/application` may have no
            // user the panel knows the password of (that endpoint links, it creates no
            // credentials), so make one. `generateAvailable` also checks the engine's own accounts.
            $user = $database->users->first() ?? $this->createUser->execute($database, [
                'username' => $this->databaseIdentifiers->generateAvailable(
                    $application->slug ?: $application->domain,
                    $database->engine,
                ),
                'password' => DatabasePassword::generate(),
                'connection_preference' => 'localhost',
            ]);
        } catch (Throwable $e) {
            throw $this->databaseFailure($application, $database->engine, $e);
        }

        $this->progress->record('create_database');

        return $this->connectionContext(
            $database->engine,
            $database->name,
            (string) $user->username,
            (string) $user->password,
        );
    }

    /**
     * Turn whatever went wrong into the failure the user is shown, the same way for creating
     * a database and reusing one.
     */
    private function databaseFailure(Application $application, string $engine, Throwable $e): ProvisioningFailedException
    {
        // Keep the reference the failure already logged under; a fresh uuid would appear in no log.
        if ($e instanceof ServerOperationException) {
            return new ProvisioningFailedException('create_database', $e->reference);
        }

        // Anything else (a bug, not a server refusal) has written nothing, so write the entry
        // before handing out the id for it.
        $reference = (string) Str::uuid();

        Log::channel('server-ops')->error('database provisioning failed', [
            'feature' => 'database',
            'op' => 'provision_database',
            'application' => $application->id,
            'engine' => $engine,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'reference' => $reference,
        ]);

        return new ProvisioningFailedException('create_database', $reference);
    }

    /**
     * What an installer needs to write its own config file.
     *
     * Host and port come from the engine's own connection record, not an assumed 127.0.0.1:3306:
     * a server with MySQL on another port would otherwise get configs pointing at nothing.
     *
     * @return array<string, mixed>
     */
    private function connectionContext(string $engine, string $database, string $username, string $password): array
    {
        $connection = $this->databases->connection($engine);

        return [
            'database' => $database,
            'db_user' => $username,
            'db_password' => $password,
            'db_host' => $connection->host ?: '127.0.0.1',
            'db_port' => (int) ($connection->port ?: config("server.databases.engines.{$engine}.default_port")),
            'db_socket' => $connection->socket,
            // Which engine it actually is: most applications treat MySQL and MariaDB as one,
            // Moodle picks a different driver for each.
            'engine' => $engine,
        ];
    }

    /**
     * The first engine the application accepts that this server actually has.
     *
     * Ordered by the application's preference, not the server's: NodeBB
     * accepts MongoDB alone, and picking whatever happened to be installed
     * first would hand it a MySQL it cannot speak.
     *
     * @param  array<int, string>  $accepted
     */
    /**
     * The engine the user asked for, when they were offered the choice.
     *
     * Null whenever the request did not carry one (every existing client, and every type with a
     * single usable engine), so the fallback stays the normal path.
     *
     * Re-checked here rather than trusted from validation: provisioning runs later in a queued
     * job, and an engine stopped or removed in between must fall back to one that answers.
     *
     * @param  array<int, string>  $accepted
     */
    private function chosenEngine(Application $application, array $accepted): ?string
    {
        $chosen = (string) (($application->settings['database_engine'] ?? '') ?: '');

        if ($chosen === '' || ! in_array($chosen, $accepted, true)) {
            return null;
        }

        return in_array($chosen, $this->databases->engineNames(), true)
            && $this->databases->engine($chosen)->available()
                ? $chosen
                : null;
    }

    /**
     * @param  array<int, string>  $accepted
     * @param  array<string, string>  $minimums
     */
    private function firstAvailableEngine(array $accepted, array $minimums = []): ?string
    {
        $installed = $this->databases->engineNames();

        foreach ($accepted as $engine) {
            // Too old counts as not available, not as a failure: fall through to the next
            // accepted engine, as for one that is not installed.
            if (in_array($engine, $installed, true)
                && $this->databases->engine($engine)->available()
                && $this->versions->meets($engine, $minimums)) {
                return $engine;
            }
        }

        return null;
    }
}
