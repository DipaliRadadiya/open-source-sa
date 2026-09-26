<?php

namespace App\Services\Server\SystemUsers;

use App\Models\SystemUser;
use App\Services\Server\Php\RuntimeOwnership;
use App\Services\Server\ServerOps;
use App\Services\Server\WebServers\WebServerManager;
use Throwable;

/**
 * Who may enter a system user's home: the user, and the web server serving
 * their sites. Nobody else.
 *
 * Homes were `chmod o+x` from 2026-08-07, so the web server could traverse
 * into them — and with it every other local account. Anything an application
 * wrote with ordinary permissions was then readable by every other site's
 * user: measured on a live box (2026-09-26) as another site's account, a
 * PrestaShop `parameters.php` and a Joomla `configuration.php` (database
 * passwords), Akaunting and Statamic `.env` files, Statamic's user password
 * hashes, Uptime Kuma's whole database, and **Laravel session files** — a
 * logged-in admin session, taken over by reading a file. The panel's own
 * secret files were 0640 and safe; the applications' were not, and a list of
 * files to lock would always be one upload, one session, one new app behind.
 *
 * So the door is the home itself: nothing for "other", and the web server let
 * in through the user's group — the same model `ApplicationLogDirectory` already uses for the
 * logs, one directory up.
 *
 * **The home, not the site root, and that choice is the safety of it.**
 * `chmod` follows a symlink, and the site root sits inside the home, where its
 * user can rename it: changing its mode means lifting its immutable flag
 * ({@see SiteRootLock}), and in that window a swapped-in link would have root
 * chmod whatever it points at. A home sits in root's `/home` — its user can
 * change what is inside it, never the entry itself.
 */
class HomeDirectoryAccess
{
    public const SECURED = 'secured';

    public const ALREADY = 'already';

    /** Not a home the panel can vouch for: outside the home base, a link, or not the user's. */
    public const SKIPPED = 'skipped';

    /** A PHP site served by the shared pool, whose workers may predate the grant. */
    public const SHARED_POOL = 'shared_pool';

    /** The web server's account is not in the group yet, so closing the home would take the sites down. */
    public const NO_READER = 'no_reader';

    public const FAILED = 'failed';

    public function __construct(
        private ServerOps $serverOps,
        private WebServerManager $webServers,
        private RuntimeOwnership $ownership,
    ) {}

    /**
     * Put the web server's account in the user's group. True only when it was
     * added — the caller restarts the web server on that, since supplementary
     * groups are read when a process starts ({@see ApplicationLogDirectory}).
     */
    public function admitReader(SystemUser $user): bool
    {
        return $this->admitReaderTo($user->username);
    }

    /**
     * A home `useradd` has just made: let the web server in, then close it.
     *
     * Nothing is served from it yet, so there is no running worker to be
     * locked out — the site that arrives later restarts or reloads the web
     * server as it publishes its vhost, and new workers pick the group up.
     *
     * Throws nothing and reports nothing back: an account whose home could not
     * be closed is an account left the way every account was before this, and
     * `sites:resync` closes it on the next deploy.
     */
    public function closeNewHome(string $username, string $home): void
    {
        $reader = $this->reader();
        $context = ['feature' => 'system_user', 'op' => 'home_new', 'system_user' => $username];

        if ($reader === null) {
            // No web server the panel can name, so nobody can be let in by
            // group. The old way, so whatever serves this home still can.
            $this->serverOps->run(['chmod', 'o+x', $home], $context, timeout: 15);

            return;
        }

        $this->admitReaderTo($username);

        if ($reader === $username || $this->isMember($reader, $username)) {
            $this->serverOps->run(['chmod', 'o-rwx', $home], $context, timeout: 15);

            return;
        }

        $this->serverOps->run(['chmod', 'o+x', $home], $context, timeout: 15);
    }

    private function admitReaderTo(string $username): bool
    {
        $reader = $this->reader();

        if ($reader === null || $reader === $username || $this->isMember($reader, $username)) {
            return false;
        }

        return $this->serverOps->run(
            ['gpasswd', '-a', $reader, $username],
            ['feature' => 'system_user', 'op' => 'home_admit_reader', 'system_user' => $username],
            timeout: 15,
        )->ok;
    }

    /**
     * Take everyone else's access to the home away.
     *
     * Refuses rather than guesses when the web server is not already in the
     * group: closing the home first would answer 403 for every site of this
     * user until someone noticed. And refuses for a user whose PHP runs in the
     * shared pool — those workers are `www-data` processes that started before
     * any grant, and only a php-fpm restart would give them the group.
     */
    public function secure(SystemUser $user): string
    {
        $home = $this->home($user);

        if ($home === null) {
            return self::SKIPPED;
        }

        $stat = $this->stat($user, $home);

        if ($stat === null || $stat['type'] !== 'directory' || $stat['owner'] !== $user->username) {
            return self::SKIPPED;
        }

        if (($stat['mode'] & 0o007) === 0) {
            return self::ALREADY;
        }

        $reader = $this->reader();

        if ($reader === null) {
            return self::NO_READER;
        }

        if ($user->applications()->exists()) {
            if ($reader !== $user->username && ! $this->isMember($reader, $user->username)) {
                return self::NO_READER;
            }

            foreach ($user->applications as $application) {
                if ($application->serving_profile === 'php' && ! $this->ownership->runsAsOwnUser($application)) {
                    return self::SHARED_POOL;
                }
            }
        }

        // `o-rwx`, not `0750`: only what everyone else could do is taken away.
        // Whatever the owner or the group had is theirs to keep.
        return $this->serverOps->run(['chmod', 'o-rwx', $home], $this->context($user, 'secure'), timeout: 15)->ok
            ? self::SECURED
            : self::FAILED;
    }

    /**
     * Null when it cannot be told: not a home under the base, or no answer.
     */
    public function isOpen(SystemUser $user): ?bool
    {
        $home = $this->home($user);
        $stat = $home === null ? null : $this->stat($user, $home);

        if ($stat === null || $stat['type'] !== 'directory') {
            return null;
        }

        return ($stat['mode'] & 0o007) !== 0;
    }

    /**
     * Only a home directly under the home base. That parent is root's, which
     * is what stops its user replacing the home with a link to somewhere root
     * would then chmod. A user server sync adopted may live anywhere, and a
     * parent the panel has not checked is not one it will chmod beneath.
     */
    private function home(SystemUser $user): ?string
    {
        $home = rtrim((string) $user->home_path, '/');
        $base = rtrim((string) config('server.home_base', '/home'), '/');

        if ($home === '' || $base === '' || dirname($home) !== $base || basename($home) !== $user->username) {
            return null;
        }

        return $home;
    }

    /**
     * `stat` without -L: a symlink is reported as one.
     *
     * @return array{type: string, owner: string, mode: int}|null
     */
    private function stat(SystemUser $user, string $home): ?array
    {
        $result = $this->serverOps->run(
            ['stat', '-c', '%F|%U|%a', $home],
            $this->context($user, 'inspect'),
            timeout: 15,
            expectedExitCodes: [1],
        );

        $parts = $result->ok ? explode('|', trim($result->output())) : [];

        if (count($parts) !== 3 || preg_match('/^[0-7]{3,4}$/', $parts[2]) !== 1) {
            return null;
        }

        return ['type' => $parts[0], 'owner' => $parts[1], 'mode' => octdec($parts[2]) & 0o777];
    }

    private function reader(): ?string
    {
        try {
            return $this->webServers->driver()->siteReaderUser();
        } catch (Throwable) {
            // No web server the panel can drive: nothing to let in, and no
            // reason to believe closing the home is safe.
            return null;
        }
    }

    /**
     * An unreadable answer counts as "not a member": for admitReader() that
     * costs one redundant gpasswd, and for secure() it leaves the home open —
     * both the safe side.
     */
    private function isMember(string $account, string $group): bool
    {
        $result = $this->serverOps->run(['id', '-nG', $account], ['feature' => 'system_user', 'op' => 'home_reader_groups'], timeout: 15);

        return $result->ok && in_array($group, preg_split('/\s+/', trim($result->output())) ?: [], true);
    }

    /** @return array<string, mixed> */
    private function context(SystemUser $user, string $op): array
    {
        return ['feature' => 'system_user', 'op' => "home_{$op}", 'system_user' => $user->username];
    }
}
