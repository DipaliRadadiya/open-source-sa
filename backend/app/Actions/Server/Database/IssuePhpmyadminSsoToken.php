<?php

namespace App\Actions\Server\Database;

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Database\PhpmyadminSsoException;
use App\Models\Application;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\PhpMyAdminSso;

/**
 * Mints a one-time SSO token for phpMyAdmin auto-login.
 *
 * The flow:
 *  1. Validate the DB is MySQL/MariaDB — phpMyAdmin supports neither
 *     MongoDB nor PostgreSQL, and the refusal names whichever it is.
 *  2. Find a running phpMyAdmin application on this server.
 *  3. Resolve the DB user (explicit or first available).
 *  4. Put the sign-in script in place, then drop a short-lived token beside it.
 *
 * The token is consumed by `sso.php` on the phpMyAdmin site, which deletes it before using
 * it. Never returns credentials, only the redirect URL.
 * {@see PhpMyAdminSso} for how the two applications reach each other.
 */
class IssuePhpmyadminSsoToken
{
    public function __construct(
        private PhpMyAdminSso $sso,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Database $database, ?int $databaseUserId, int $userId, ?int $applicationId = null): string
    {
        $this->assertSqlEngine($database);
        $pmaApp = $this->resolvePhpmyadminApp($applicationId);
        $dbUser = $this->resolveDatabaseUser($database, $databaseUserId);

        // The sign-in link carries a live database session, and the session cookie it buys follows
        // every request; over plain HTTP both cross the network in clear text. Refuse, as Magic
        // Login does.
        if ($pmaApp->scheme() !== 'https') {
            throw new PhpmyadminSsoException(
                message: __('errors/database.phpmyadmin_requires_https'),
                feature: 'database',
            );
        }

        if (! $this->sso->canIssue($pmaApp)) {
            throw new PhpmyadminSsoException(
                message: __('errors/database.phpmyadmin_not_isolated'),
                feature: 'database',
            );
        }

        // Written on every click, not once at install, so existing sites without the script work too.
        if ($this->sso->installShim($pmaApp)->failed()) {
            throw new PhpmyadminSsoException(
                message: __('errors/database.phpmyadmin_sso_unavailable'),
                feature: 'database',
            );
        }

        // An unclicked token still holds a password until something removes it.
        $this->sso->sweep($pmaApp);

        $url = $this->sso->issue(
            $pmaApp,
            $dbUser->username,
            $dbUser->password, // encrypted cast auto-decrypts
            $database->name,
        );

        if ($url === null) {
            throw new PhpmyadminSsoException(
                message: __('errors/database.phpmyadmin_sso_unavailable'),
                feature: 'database',
            );
        }

        // Audited: this grants a live database session without a password. The actor is passed
        // explicitly so the entry stays right when called from a job.
        $this->activityLogger->log(
            'database.phpmyadmin_signed_in',
            $database,
            ['name' => $database->name, 'username' => $dbUser->username],
            User::find($userId),
        );

        return $url;
    }

    private function assertSqlEngine(Database $database): void
    {
        if ($database->driver() !== 'sql') {
            throw new PhpmyadminSsoException(
                // Names the engine the database actually uses.
                message: __('errors/database.phpmyadmin_engine_not_supported', [
                    'engine' => (string) config("server.databases.engines.{$database->engine}.label", $database->engine),
                ]),
                feature: 'database',
            );
        }
    }

    /**
     * The phpMyAdmin to sign into: the one asked for, or a stable default.
     *
     * The same query answers both the named and the default case, so they cannot disagree about
     * what counts as usable. Status is `Active`; there is no Running case.
     */
    private function resolvePhpmyadminApp(?int $applicationId): Application
    {
        $query = Application::query()
            ->where('site_type', 'phpmyadmin')
            ->where('status', ApplicationStatus::Active);

        if ($applicationId !== null) {
            $pma = (clone $query)->whereKey($applicationId)->first();

            if (! $pma) {
                // Distinct from "none deployed": the named site is not an Active phpMyAdmin.
                throw new PhpmyadminSsoException(
                    message: __('errors/database.phpmyadmin_not_selectable'),
                    feature: 'database',
                );
            }

            return $pma;
        }

        // Oldest first, so an unnamed choice is the same one every time.
        $pma = $query->orderBy('id')->first();

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
