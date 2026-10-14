<?php

namespace App\Actions\Server\Database;

use App\Enums\ApplicationStatus;
use App\Exceptions\Server\Database\AdminerSsoException;
use App\Models\Application;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\AdminerSso;

/**
 * Mints a one-time sign-in link to Adminer for one database.
 *
 * The same flow and the same refusals as {@see IssuePhpmyadminSsoToken}, for
 * the engines Adminer opens here: MySQL, MariaDB and PostgreSQL. Never returns
 * credentials — only the redirect URL.
 */
class IssueAdminerSsoToken
{
    public function __construct(
        private AdminerSso $sso,
        private ActivityLogger $activityLogger,
    ) {}

    public function execute(Database $database, ?int $databaseUserId, int $userId, ?int $applicationId = null): string
    {
        $server = $this->sso->serverFor($database->engine);

        if ($server === null) {
            throw new AdminerSsoException(
                message: __('errors/database.adminer_engine_not_supported', [
                    'engine' => (string) config("server.databases.engines.{$database->engine}.label", $database->engine),
                ]),
                feature: 'database',
            );
        }

        $adminer = $this->resolveAdminerApp($applicationId);
        $dbUser = $this->resolveDatabaseUser($database, $databaseUserId);

        // A user adopted from another panel: the engine holds a hash and the
        // panel never learned the password, so there is nothing to sign in
        // with. Said plainly rather than sending the user to a login form that
        // will reject an empty password.
        if ($dbUser->password === null || $dbUser->password === '') {
            throw new AdminerSsoException(
                message: __('errors/database.adminer_password_unknown', ['username' => $dbUser->username]),
                feature: 'database',
            );
        }

        // The link carries a live database session and the cookie it buys is
        // then sent with every request; over plain HTTP both cross the network
        // in clear text (phpMyAdmin's bug #98).
        if ($adminer->scheme() !== 'https') {
            throw new AdminerSsoException(
                message: __('errors/database.adminer_requires_https'),
                feature: 'database',
            );
        }

        if (! $this->sso->canIssue($adminer)) {
            throw new AdminerSsoException(
                message: __('errors/database.adminer_not_isolated'),
                feature: 'database',
            );
        }

        // Written on every click, not once at install, so a change to the
        // wrapper or the sign-in script reaches sites that already exist.
        if ($this->sso->installShim($adminer)->failed()) {
            throw new AdminerSsoException(
                message: __('errors/database.adminer_sso_unavailable'),
                feature: 'database',
            );
        }

        // Tokens expire on read, so an unclicked one is already worthless —
        // but it holds a password until something removes it.
        $this->sso->sweep($adminer);

        $url = $this->sso->issue(
            $adminer,
            $server,
            $dbUser->username,
            $dbUser->password, // encrypted cast auto-decrypts
            $database->name,
        );

        if ($url === null) {
            throw new AdminerSsoException(
                message: __('errors/database.adminer_sso_unavailable'),
                feature: 'database',
            );
        }

        // Which panel user opened which database as which account — the
        // question an audit asks of a password-less database session.
        $this->activityLogger->log(
            'database.adminer_signed_in',
            $database,
            ['name' => $database->name, 'username' => $dbUser->username],
            User::find($userId),
        );

        return $url;
    }

    /**
     * The Adminer to sign into: the one asked for, or the oldest Active one so
     * that an unnamed choice is the same one every time.
     */
    private function resolveAdminerApp(?int $applicationId): Application
    {
        $query = Application::query()
            ->where('site_type', 'adminer')
            ->where('status', ApplicationStatus::Active);

        if ($applicationId !== null) {
            $adminer = (clone $query)->whereKey($applicationId)->first();

            if (! $adminer) {
                throw new AdminerSsoException(
                    message: __('errors/database.adminer_not_selectable'),
                    feature: 'database',
                );
            }

            return $adminer;
        }

        $adminer = $query->orderBy('id')->first();

        if (! $adminer) {
            throw new AdminerSsoException(
                message: __('errors/database.adminer_not_deployed'),
                feature: 'database',
            );
        }

        return $adminer;
    }

    private function resolveDatabaseUser(Database $database, ?int $databaseUserId): DatabaseUser
    {
        if ($databaseUserId !== null) {
            $user = $database->users()->find($databaseUserId);

            if (! $user) {
                throw new AdminerSsoException(
                    message: __('errors/database.adminer_user_not_found'),
                    feature: 'database',
                );
            }

            return $user;
        }

        $user = $database->users()->orderBy('id')->first();

        if (! $user) {
            throw new AdminerSsoException(
                message: __('errors/database.adminer_no_users'),
                feature: 'database',
            );
        }

        return $user;
    }
}
