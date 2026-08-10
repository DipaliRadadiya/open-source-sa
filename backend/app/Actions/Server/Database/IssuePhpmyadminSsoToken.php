<?php

namespace App\Actions\Server\Database;

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Database\PhpmyadminSsoException;
use App\Models\Application;
use App\Models\Database;
use App\Models\DatabaseUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Mints a one-time SSO token for phpMyAdmin auto-login.
 *
 * The flow:
 *  1. Validate the DB is MySQL/MariaDB (phpMyAdmin does not support MongoDB).
 *  2. Find a running phpMyAdmin application on this server.
 *  3. Resolve the DB user (explicit or first available).
 *  4. Store a short-lived token in the cache, then return a redirect URL.
 *
 * The token is consumed atomically by the sso.php shim on the PMA site.
 * Never returns credentials — only the redirect URL.
 */
class IssuePhpmyadminSsoToken
{
    private const TOKEN_TTL_SECONDS = 60;

    public function execute(Database $database, ?int $databaseUserId, int $userId): string
    {
        $this->assertSqlEngine($database);
        $pmaApp = $this->resolvePhpmyadminApp();
        $dbUser = $this->resolveDatabaseUser($database, $databaseUserId);

        $token = Str::random(64);

        Cache::put("pma_sso:{$token}", [
            'user_id' => $userId,
            'database_id' => $database->id,
            'db_user_id' => $dbUser->id,
            'database_user_username' => $dbUser->username,
            'database_user_password' => $dbUser->password, // encrypted cast auto-decrypts
            'database_name' => $database->name,
            'pma_domain' => $pmaApp->domain,
        ], self::TOKEN_TTL_SECONDS);

        return "https://{$pmaApp->domain}/sso.php?token={$token}";
    }

    private function assertSqlEngine(Database $database): void
    {
        if ($database->driver() !== 'sql') {
            throw new PhpmyadminSsoException(
                message: __('errors/database.phpmyadmin_mongo_not_supported'),
                feature: 'database',
            );
        }
    }

    private function resolvePhpmyadminApp(): Application
    {
        $pma = Application::query()
            ->where('site_type', 'phpmyadmin')
            ->where('status', ApplicationStatus::Running)
            ->first();

        if (! $pma) {
            throw new PhpmyadminSsoException(
                message: __('errors/database.phpmyadmin_not_deployed'),
                feature: 'database',
            );
        }

        return $pma;
    }

    private function resolveDatabaseUser(Database $database, ?int $databaseUserId): DatabaseUser
    {
        if ($databaseUserId !== null) {
            $user = $database->users()->find($databaseUserId);

            if (! $user) {
                throw new PhpmyadminSsoException(
                    message: __('errors/database.phpmyadmin_user_not_found'),
                    feature: 'database',
                );
            }

            return $user;
        }

        $user = $database->users()->first();

        if (! $user) {
            throw new PhpmyadminSsoException(
                message: __('errors/database.phpmyadmin_no_users'),
                feature: 'database',
            );
        }

        return $user;
    }
}
