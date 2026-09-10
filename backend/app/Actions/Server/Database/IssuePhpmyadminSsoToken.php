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
 * The token is consumed by `sso.php` on the phpMyAdmin site, which deletes it
 * before using it. Never returns credentials — only the redirect URL.
 *
 * Step 4 used to write the token into the panel's cache and hand back a URL to
 * a `sso.php` that nothing had ever written, over `https://` whether or not the
 * site had a certificate. Neither half could work, which is why the button has
 * never signed anybody in. {@see PhpMyAdminSso} for how the two applications
 * actually reach each other.
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

        if (! $this->sso->canIssue($pmaApp)) {
            throw new PhpmyadminSsoException(
                message: __('errors/database.phpmyadmin_not_isolated'),
                feature: 'database',
            );
        }

        // Written on every click, not once at install. Every phpMyAdmin site
        // the panel has created so far has no sign-in script at all, and a
        // repair that only runs for new sites would fix nobody's.
        if ($this->sso->installShim($pmaApp)->failed()) {
            throw new PhpmyadminSsoException(
                message: __('errors/database.phpmyadmin_sso_unavailable'),
                feature: 'database',
            );
        }

        // Tokens expire on read, so an unclicked one is already worthless —
        // but it holds a password until something removes it.
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

        // Worth a row of its own: this hands someone a live database session
        // without them typing a password, so which panel user opened which
        // database as which account is exactly the question an audit asks. The
        // actor is passed explicitly rather than read from the session, so the
        // entry is still right if this is ever called from a job.
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
                // Names the engine the database actually uses. The message was
                // hardcoded to MongoDB, which was the only non-SQL engine when
                // it was written — so a PostgreSQL database was refused with a
                // sentence about a database it has nothing to do with, which
                // reads as a panel bug rather than an answer.
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
     * `$applicationId` used to not exist, and this took `->first()` with no
     * ordering — so on a server with more than one installation the panel
     * picked, the user could not, and which one they got could change between
     * requests. A client could not correct it afterwards either: the token is
     * written into the chosen site's own directory, so the choice has to be
     * made here or not at all.
     *
     * The same query answers both cases, which is the point — a named site and
     * the default cannot disagree about what counts as usable.
     *
     * `Active`, not `Running`: there is no Running case — a site is Pending,
     * Provisioning, Active or Failed. Referencing a case that does not exist is
     * a fatal, so this path could only ever 500.
     */
    private function resolvePhpmyadminApp(?int $applicationId): Application
    {
        $query = Application::query()
            ->where('site_type', 'phpmyadmin')
            ->where('status', ApplicationStatus::Active);

        if ($applicationId !== null) {
            $pma = (clone $query)->whereKey($applicationId)->first();

            if (! $pma) {
                // Distinct from "none deployed": naming a site that is not an
                // Active phpMyAdmin is a different mistake from having none,
                // and telling the user the second when they made the first
                // sends them to install something they already have.
                throw new PhpmyadminSsoException(
                    message: __('errors/database.phpmyadmin_not_selectable'),
                    feature: 'database',
                );
            }

            return $pma;
        }

        // Oldest first, so an unnamed choice is at least the *same* one every
        // time. `first()` on an unordered query is whatever the engine
        // returns, which can differ between two identical requests.
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
