<?php

namespace App\Services\Server\Applications;

use App\Actions\Server\Database\CreateDatabase;
use App\Actions\Server\Database\CreateDatabaseUser;
use App\Contracts\SiteInstaller;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Exceptions\Server\ServerOperationException;
use App\Models\Application;
use App\Models\Database;
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
 * Site types with no installer — git, blank PHP, static — pass straight
 * through. There is nothing to install for a site whose contents the user
 * supplies.
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
     * `installerForType()` resolves the class, and a PHP installer asks the
     * container for the `PhpStack` — which on a server with no capability
     * record yet shells out to detect one. That is fine inside a queued job and
     * wrong in an HTTP request that only wants to know whether an installer
     * exists.
     */
    public function hasInstaller(string $siteType): bool
    {
        return config("server.installers.{$siteType}.driver") !== null;
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

        return $class === null ? null : app($class);
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
     * The password is generated, never asked for — one fewer weak secret, and
     * the user never needs to see it since it only ever goes into the app's
     * own config file.
     *
     * @param  array<int, string>  $accepted  engines this application can use
     * @param  array<string, string>  $minimums  its minimum version per engine
     * @return array<string, mixed>
     *
     * @throws ProvisioningFailedException
     */
    private function provisionDatabase(Application $application, array $accepted, array $minimums = []): array
    {
        // Retry Setup must not mint a second database.
        //
        // This method used to go straight to generating a name, so every retry
        // after a failure past this point left another `shop_xqolim` beside the
        // last one — schemas and accounts the user never asked for, on a server
        // where nothing but the panel knows which one the site actually uses.
        // An application has at most one database by design (see
        // UpdateDatabaseApplicationRequest), so "it already has one" is a
        // complete answer rather than a heuristic.
        if (($attached = $application->databases()->with('users')->first()) !== null) {
            return $this->reuseDatabase($application, $attached, $accepted);
        }

        // An engine the user chose was already held to the minimum by
        // StoreApplicationRequest, so this honours a validated choice rather
        // than re-deciding it. Only the fallback — where the panel is the one
        // picking — consults the version.
        $engine = $this->chosenEngine($application, $accepted)
            ?? $this->firstAvailableEngine($accepted, $minimums);

        if ($engine === null) {
            // Fail here rather than half-installing: without a database the
            // application cannot work, and the user needs to know it is the
            // database engine that is missing, not their input.
            throw new ProvisioningFailedException('create_database', 'no-database-engine');
        }

        $password = DatabasePassword::generate();

        try {
            // Named from the slug, not the domain. The domain is a hostname:
            // every dot becomes an underscore, so `shop.example.co.in` produced
            // `shop_example_co_in_xqolim`, and a nip.io host was truncated
            // mid-word to fit MySQL's 32-character account limit. The slug is
            // already the short, unique, normalized name the site's own
            // directory uses, so `/home/deploy/shop` now pairs with
            // `shop_xqolim`. Rows predating the slug column fall back to the
            // old behaviour rather than to the generator's 'app' placeholder.
            //
            // `generateAvailable`, not `generate`: this was the only one of the
            // three callers that never checked the name was free, on either the
            // panel's rows or the engine. A collision surfaced from the catch
            // below as an opaque create_database failure with no cause, and a
            // shorter label makes collisions likelier, not rarer.
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

        // `create_database` was in the documented step list and was never
        // actually emitted — the only step the frontend was told to expect that
        // could not appear.
        $this->progress->record('create_database');

        return $this->connectionContext($engine, $database->name, $name, $password);
    }

    /**
     * Hand the installer the database this application already has.
     *
     * Reachable in two ways: a retry after provisioning failed somewhere past
     * the database step, and an application that had one attached to it
     * deliberately. Both want the same thing — install against what is there.
     *
     * @param  array<int, string>  $accepted
     * @return array<string, mixed>
     *
     * @throws ProvisioningFailedException
     */
    private function reuseDatabase(Application $application, Database $database, array $accepted): array
    {
        // An engine this site type cannot speak is not something to work
        // around. Creating a second database beside it is the bug being fixed
        // here, and installing against an engine the application has no driver
        // for fails later, further away, in the application's own words.
        if (! in_array($database->engine, $accepted, true)) {
            throw new ProvisioningFailedException(
                'create_database',
                // A sentinel rather than a uuid, as `no-database-engine` above:
                // nothing shelled out, so there is no server-ops entry for a
                // reference to point at. The reason code carries the meaning.
                'attached-database-engine-mismatch',
                'attached_database_engine_mismatch',
            );
        }

        try {
            // A database attached through `PUT /databases/{database}/application`
            // may have no user the panel knows the password of — that endpoint
            // links an existing database, it does not create credentials. The
            // installer needs some, so make them rather than refuse.
            //
            // `generateAvailable` for the name: it checks the engine's own
            // accounts as well as the panel's rows, so this cannot collide with
            // a user the adopted database already had.
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
     * Turn whatever went wrong into the failure the user is shown.
     *
     * Shared by both paths — creating a database and reusing one — so a
     * failure in either is reported the same way rather than one of them
     * growing its own handling later.
     */
    private function databaseFailure(Application $application, string $engine, Throwable $e): ProvisioningFailedException
    {
        // Keep the reference the failure already logged under. Minting a
        // fresh uuid here handed the user an id that appears in no log,
        // while the one the server-ops entry was written with was thrown
        // away — the reference is only useful if it points at something.
        if ($e instanceof ServerOperationException) {
            return new ProvisioningFailedException('create_database', $e->reference);
        }

        // Anything else — a bug rather than a server refusal — has written
        // nothing, so write the entry before handing out the id for it.
        // Otherwise the fallback keeps the same defect the branch above
        // was fixing, only for the failures that are hardest to diagnose.
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
     * Read the host and port off the engine's own connection record rather
     * than assuming 127.0.0.1:3306. A server whose MySQL listens on a
     * non-default port would otherwise have every installer write a config
     * pointing at a port nothing is on — and the failure surfaces as the
     * application's own "cannot connect to database", not as ours.
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
            // Which engine it actually is. Most applications treat MySQL and
            // MariaDB as one thing; Moodle picks a different driver for each.
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
     * Null whenever the request did not carry one, which is every existing
     * client and every type with only one usable engine — so the fallback
     * below stays the normal path rather than a legacy branch.
     *
     * Re-checked here rather than trusted from validation: the request was
     * validated when it was made, and provisioning runs later in a queued job.
     * An engine that was stopped or removed in between must fall back to one
     * that answers, not fail the whole install on a stale choice.
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
            // Too old counts as not available here rather than as a failure:
            // an application that accepts three engines and finds the first
            // one below its minimum should fall through to the next, exactly
            // as it does for one that is not installed. Only when nothing
            // qualifies does this return null and provisioning stop.
            if (in_array($engine, $installed, true)
                && $this->databases->engine($engine)->available()
                && $this->versions->meets($engine, $minimums)) {
                return $engine;
            }
        }

        return null;
    }
}
