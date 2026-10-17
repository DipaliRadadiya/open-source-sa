<?php

namespace App\Actions\Server\Database;

use App\Models\DatabaseConnection;
use App\Services\ActivityLogger;
use App\Services\Server\Databases\DatabaseManager;
use App\Services\Server\Databases\SqlPasswordCheck;
use Illuminate\Validation\ValidationException;

class SaveConnection
{
    public function __construct(
        private DatabaseManager $manager,
        private ActivityLogger $activityLogger,
        private SqlPasswordCheck $check,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(string $engine, array $data): DatabaseConnection
    {
        $connection = $this->manager->connection($engine);

        $rootPassword = $data['root_password'] ?? null;
        unset($data['root_password']);

        if (is_string($rootPassword) && $rootPassword !== '') {
            if (! in_array($engine, ['mysql', 'mariadb'], true)) {
                throw ValidationException::withMessages(['root_password' => [__('errors/database.root_password_unsupported')]]);
            }

            // Stored only once the server has accepted it: a wrong one saved
            // here would be what the panel falls back to when it needs root.
            if (! $this->check->works($engine, $rootPassword)) {
                throw ValidationException::withMessages(['root_password' => [__('errors/database.root_password_wrong')]]);
            }

            $connection->root_password = $rootPassword;
        }

        $connection->fill($data)->save();

        $this->activityLogger->log('database.connection_updated', $connection, ['engine' => $engine]);

        return $connection->refresh();
    }
}
