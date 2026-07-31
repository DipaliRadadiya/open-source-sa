<?php

namespace App\Services\Server\Applications;

use App\Actions\Server\Database\CreateDatabase;
use App\Contracts\SiteInstaller;
use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\DatabasePassword;
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
        private DatabaseManager $databases,
    ) {}

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
     * @return array<int, string> steps completed (empty when nothing to install)
     *
     * @throws ProvisioningFailedException
     */
    public function install(Application $application, string $documentRoot): array
    {
        $installer = $this->installerFor($application);

        if ($installer === null) {
            return [];
        }

        $context = [];

        if ($installer->needsDatabase()) {
            $context = $this->provisionDatabase($application, $installer->acceptedEngines());
        }

        return $installer->install($application, $documentRoot, $context);
    }

    /**
     * Create the application's database and a dedicated user for it.
     *
     * The password is generated, never asked for — one fewer weak secret, and
     * the user never needs to see it since it only ever goes into the app's
     * own config file.
     *
     * @param  array<int, string>  $accepted  engines this application can use
     * @return array<string, mixed>
     *
     * @throws ProvisioningFailedException
     */
    private function provisionDatabase(Application $application, array $accepted): array
    {
        $engine = $this->firstAvailableEngine($accepted);

        if ($engine === null) {
            // Fail here rather than half-installing: without a database the
            // application cannot work, and the user needs to know it is the
            // database engine that is missing, not their input.
            throw new ProvisioningFailedException('create_database', 'no-database-engine');
        }

        $name = $this->identifier($application);
        $password = DatabasePassword::generate();

        try {
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
            throw new ProvisioningFailedException('create_database', (string) Str::uuid());
        }

        // Read the host and port off the engine's own connection record
        // rather than assuming 127.0.0.1:3306. A server whose MySQL listens
        // on a non-default port would otherwise have every installer write a
        // config pointing at a port nothing is on — and the failure surfaces
        // as the application's own "cannot connect to database", not as ours.
        $connection = $this->databases->connection($engine);

        return [
            'database' => $database->name,
            'db_user' => $name,
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
     * A database identifier derived from the domain: predictable for the user,
     * and constrained to the charset the engine accepts.
     */
    private function identifier(Application $application): string
    {
        $base = Str::of($application->domain)
            ->replace('.', '_')
            ->replaceMatches('/[^A-Za-z0-9_]/', '')
            ->lower()
            ->limit(48, '')
            ->value();

        return ($base ?: 'app').'_'.Str::lower(Str::random(6));
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
    private function firstAvailableEngine(array $accepted): ?string
    {
        $installed = $this->databases->engineNames();

        foreach ($accepted as $engine) {
            if (in_array($engine, $installed, true) && $this->databases->engine($engine)->available()) {
                return $engine;
            }
        }

        return null;
    }
}
