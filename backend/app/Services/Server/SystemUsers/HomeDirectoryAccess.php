<?php

namespace App\Services\Server\SystemUsers;

use App\Models\SystemUser;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use App\Services\Server\WebServers\WebServerManager;
use Throwable;

/**
 * Who may enter a system user's home.
 *
 * A plain `chmod o+x` lets the web server pass through, but also every other local account, and
 * apps write most files `644`: any site user could read another site's `.env` and database
 * password by path. Closing the home with `o-rwx` and a group for the web server breaks the
 * panel's own account (every installer, deploy, backup and certificate job starts in a site
 * directory), and a supplementary group is not seen by the already-running queue worker.
 *
 * So the home gets an ACL: pass-through (`--x`, no listing) for exactly the panel's account and
 * the web server's, nothing for anyone else. ACLs apply at once to running processes.
 */
class HomeDirectoryAccess
{
    public const SECURED = 'secured';

    public const ALREADY = 'already';

    public const SKIPPED = 'skipped';

    /** setfacl is not installed. */
    public const NO_ACL = 'no_acl';

    /** The web server's account is not known, so it cannot be let in. */
    public const NO_READER = 'no_reader';

    public const FAILED = 'failed';

    public function __construct(
        private ServerOps $serverOps,
        private WebServerManager $webServers,
    ) {}

    /**
     * Close a home `useradd` has just made. Never throws and never locks the web server out:
     * without setfacl, or when the ACL does not take, the home gets `o+x` and the Doctor says so.
     * Null when the home was closed; otherwise the fallback's result.
     */
    public function closeNewHome(string $username, string $home): ?ServerOpsResult
    {
        $context = ['feature' => 'system_user', 'system_user' => $username];

        try {
            if ($this->toolsInstalled() && $this->entries() !== null && $this->apply($home, $context) && $this->verify($home, $context)) {
                return null;
            }
        } catch (Throwable) {
            // Fall through to the old, open behaviour.
        }

        return $this->serverOps->run(['chmod', 'o+x', $home], $context + ['op' => 'home_open_fallback'], timeout: 15);
    }

    /**
     * Close an existing user's home. Only a real directory directly under the home base, named
     * after and owned by the user; anything else (a link, a shared path) is left alone.
     */
    public function secure(SystemUser $user): string
    {
        $home = $this->home($user);

        if ($home === null || ! $this->isOwnHome($user, $home)) {
            return self::SKIPPED;
        }

        if (! $this->toolsInstalled()) {
            return self::NO_ACL;
        }

        if ($this->entries() === null) {
            return self::NO_READER;
        }

        $context = $this->context($user);

        if ($this->verify($home, $context)) {
            return self::ALREADY;
        }

        // Read back, not taken from the exit code.
        return $this->apply($home, $context) && $this->verify($home, $context) ? self::SECURED : self::FAILED;
    }

    /**
     * Undo it: drop the ACL and restore `o+x` (the rollback behind `homes:open`).
     */
    public function open(SystemUser $user): bool
    {
        $home = $this->home($user);

        if ($home === null || ! $this->isOwnHome($user, $home)) {
            return false;
        }

        $context = $this->context($user);

        if ($this->toolsInstalled()) {
            $this->serverOps->run(['setfacl', '-b', $home], $context + ['op' => 'home_acl_remove'], timeout: 15);
        }

        return $this->serverOps->run(['chmod', 'o+x', $home], $context + ['op' => 'home_open'], timeout: 15)->ok;
    }

    /**
     * Whether other local accounts can enter this home; null when unknown.
     */
    public function isOpen(SystemUser $user): ?bool
    {
        $home = $this->home($user);

        if ($home === null) {
            return null;
        }

        $result = $this->serverOps->run(['stat', '-c', '%a', $home], $this->context($user) + ['op' => 'home_inspect'], timeout: 15);

        return $result->ok && preg_match('/^[0-7]{3,4}$/', trim($result->output())) === 1
            ? (octdec(trim($result->output())) & 0o007) !== 0
            : null;
    }

    /**
     * Install the `acl` package when setfacl is missing (Ubuntu 26.04 lacks it). Non-fatal: the
     * caller reports NO_ACL and homes stay open.
     */
    public function ensureTools(): bool
    {
        if ($this->toolsInstalled()) {
            return true;
        }

        $this->serverOps->apt(
            ['apt-get', 'install', '-y', '--no-install-recommends', 'acl'],
            ['feature' => 'system_user', 'op' => 'install_acl'],
            env: ['DEBIAN_FRONTEND' => 'noninteractive'],
        );

        return $this->toolsInstalled();
    }

    /**
     * The ACL a closed home carries, or null when the web server's account is unknown (closing
     * the home would make every site of that user answer 403).
     *
     * @return array<int, string>|null
     */
    public function entries(): ?array
    {
        $reader = $this->webServers->driver()->siteReaderUser();
        $panel = $this->panelAccount();

        if ($reader === null || $reader === '' || $panel === '') {
            return null;
        }

        return array_values(array_unique(["u:{$panel}:--x", "u:{$reader}:--x"]));
    }

    public function panelAccount(): string
    {
        $configured = (string) config('server.panel_account', '');

        if ($configured !== '') {
            return $configured;
        }

        $user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';

        return (string) $user;
    }

    private function toolsInstalled(): bool
    {
        return $this->serverOps->run(['which', 'setfacl'], ['feature' => 'system_user', 'op' => 'acl_check'], timeout: 15)->ok;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function apply(string $home, array $context): bool
    {
        return $this->serverOps->run(
            ['setfacl', '-m', implode(',', [...($this->entries() ?? []), 'o::---']), $home],
            $context + ['op' => 'home_close'],
            timeout: 15,
        )->ok;
    }

    /**
     * Every entry present and others shut out, read back from the directory.
     *
     * @param  array<string, mixed>  $context
     */
    private function verify(string $home, array $context): bool
    {
        $entries = $this->entries();

        if ($entries === null) {
            return false;
        }

        $result = $this->serverOps->run(['getfacl', '-cp', $home], $context + ['op' => 'home_acl_read'], timeout: 15);

        if ($result->failed()) {
            return false;
        }

        $acl = array_map('trim', explode("\n", $result->output()));

        foreach ($entries as $entry) {
            // `u:panel:--x` is printed as `user:panel:--x`.
            if (! in_array('user:'.substr($entry, 2), $acl, true)) {
                return false;
            }
        }

        return in_array('other::---', $acl, true);
    }

    private function home(SystemUser $user): ?string
    {
        $home = rtrim((string) $user->home_path, '/');
        $base = rtrim((string) config('server.home_base', '/home'), '/');

        if ($home === '' || $base === '' || dirname($home) !== $base || basename($home) !== $user->username) {
            return null;
        }

        return $home;
    }

    private function isOwnHome(SystemUser $user, string $home): bool
    {
        $result = $this->serverOps->run(
            ['stat', '-c', '%F|%U', $home],
            $this->context($user) + ['op' => 'home_inspect'],
            timeout: 15,
        );

        return $result->ok && trim($result->output()) === 'directory|'.$user->username;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(SystemUser $user): array
    {
        return ['feature' => 'system_user', 'system_user' => $user->username];
    }
}
