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
        $class = config("server.installers.{$application->site_type}.driver");

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
            $context = $this->provisionDatabase($application);
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
     * @return array<string, mixed>
     *
     * @throws ProvisioningFailedException
     */
    private function provisionDatabase(Application $application): array
    {
        $engine = $this->firstAvailableEngine();

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

        return [
            'database' => $database->name,
            'db_user' => $name,
            'db_password' => $password,
            'db_host' => '127.0.0.1',
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

    private function firstAvailableEngine(): ?string
    {
        foreach ($this->databases->engineNames() as $engine) {
            if ($this->databases->driver($engine) === 'sql' && $this->databases->engine($engine)->available()) {
                return $engine;
            }
        }

        return null;
    }
}
