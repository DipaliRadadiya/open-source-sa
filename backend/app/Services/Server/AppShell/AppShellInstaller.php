<?php

namespace App\Services\Server\AppShell;

use App\Services\Server\AccountLock;
use App\Services\Server\ManagedFile;
use App\Services\Server\ServerOps;

/**
 * Puts container login on a server, idempotently (RC-28).
 *
 * Run by `containers:sync-shell-access` on install and on every panel update,
 * so a server installed before this existed gets it without a reinstall, and
 * again before anyone's container login is switched on, so a server whose
 * update step failed still gets it.
 *
 * Each file is compared with what is on disk and only rewritten when it
 * differs. The two that can lock people out are validated before they take
 * effect, and a failure leaves the server as it was:
 *
 *  - the sudoers drop-in is written beside its target, checked with
 *    `visudo -cqf`, and only then renamed into place (PanelSudoers' rule);
 *  - the sshd drop-in is checked with `sshd -t` before sshd is reloaded, and
 *    put back as it was when the check fails, so a bad block never reaches
 *    the running daemon and never touches root or anyone outside the group.
 */
class AppShellInstaller
{
    public const UNCHANGED = 'unchanged';

    public const UPDATED = 'updated';

    public const FAILED = 'failed';

    public function __construct(
        private AppShellFiles $files,
        private ServerOps $serverOps,
        private ManagedFile $managed,
        private AccountLock $accountLock,
    ) {}

    /**
     * @return array{group: string, helper: string, login: string, sudoers: string, sshd: string}
     */
    public function ensure(): array
    {
        if (! $this->files->configured()) {
            return array_fill_keys(['group', 'helper', 'login', 'sudoers', 'sshd'], self::FAILED);
        }

        $group = $this->accountLock->run(fn () => $this->serverOps->run(
            ['groupadd', '-f', $this->files->group()],
            $this->context('group'),
        ));

        return [
            'group' => $group->failed() ? self::FAILED : self::UNCHANGED,
            'helper' => $this->installExecutable($this->files->helperPath(), $this->files->helper(), 'helper'),
            'login' => $this->installExecutable($this->files->loginPath(), $this->files->login(), 'login'),
            'sudoers' => $this->installSudoers(),
            'sshd' => $this->installSshd(),
        ];
    }

    /**
     * Does sshd, as configured right now, force this user's logins through
     * the wrapper?
     *
     * The drop-in only works where the main sshd_config includes
     * sshd_config.d, which every supported Ubuntu does and a hand-edited
     * server may not. Without that include, a user put in the group would get
     * an ordinary host shell — so the switch asks sshd itself, for this user,
     * before it reports container login as on.
     */
    public function enforcedFor(string $username): bool
    {
        $result = $this->serverOps->run(
            ['sshd', '-T', '-C', "user={$username},host=localhost,addr=127.0.0.1"],
            $this->context('verify'),
        );

        if ($result->failed()) {
            return false;
        }

        foreach (preg_split('/\r?\n/', $result->output()) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line), 2);

            if (strtolower($parts[0] ?? '') === 'forcecommand') {
                return trim($parts[1] ?? '') === $this->files->loginPath();
            }
        }

        return false;
    }

    private function installExecutable(string $path, string $content, string $what): string
    {
        if ($this->read($path) === $content) {
            return self::UNCHANGED;
        }

        $temporary = $path.'.new';
        $context = $this->context($what);

        foreach ([
            fn () => $this->serverOps->run(['install', '-d', '-m', '0755', '-o', 'root', '-g', 'root', dirname($path)], $context),
            fn () => $this->serverOps->run(['tee', $temporary], $context, input: $content),
            fn () => $this->serverOps->run(['chown', 'root:root', $temporary], $context),
            fn () => $this->serverOps->run(['chmod', '0755', $temporary], $context),
            fn () => $this->serverOps->run(['mv', '-f', $temporary, $path], $context),
        ] as $step) {
            if ($step()->failed()) {
                $this->managed->delete($temporary, $context);

                return self::FAILED;
            }
        }

        return self::UPDATED;
    }

    private function installSudoers(): string
    {
        $path = $this->files->sudoersPath();
        $content = $this->files->sudoers();

        if ($this->read($path) === $content) {
            return self::UNCHANGED;
        }

        // Beside the target, so the last step is a rename on one filesystem.
        // sudo ignores names containing a dot, so the `.new` file is never
        // read as a rule while it is being checked.
        $temporary = $path.'.new';
        $context = $this->context('sudoers');

        foreach ([
            fn () => $this->serverOps->run(['tee', $temporary], $context, input: $content),
            fn () => $this->serverOps->run(['chmod', '0440', $temporary], $context),
            fn () => $this->serverOps->run(['visudo', '-cqf', $temporary], $context),
            fn () => $this->serverOps->run(['mv', '-f', $temporary, $path], $context),
        ] as $step) {
            if ($step()->failed()) {
                $this->managed->delete($temporary, $context);

                return self::FAILED;
            }
        }

        return self::UPDATED;
    }

    private function installSshd(): string
    {
        $path = $this->files->sshdPath();
        $content = $this->files->sshd();
        $previous = $this->read($path);

        if ($previous === $content) {
            return self::UNCHANGED;
        }

        $context = $this->context('sshd');

        // Written in place: `sshd -t` checks the configuration sshd would load,
        // and a file under another name is not part of it. The running daemon
        // reads nothing until the reload below, which only follows a pass.
        if ($this->managed->put($path, $content, $context)->failed()) {
            return self::FAILED;
        }

        if ($this->serverOps->run(['sshd', '-t'], $context)->failed()) {
            $previous === null
                ? $this->managed->delete($path, $context)
                : $this->managed->put($path, $previous, $context);

            return self::FAILED;
        }

        // try-reload-or-restart, as SecuritySettings does: under socket
        // activation ssh.service may not be running until someone connects.
        $reload = $this->serverOps->run(['systemctl', 'try-reload-or-restart', 'ssh'], $context);

        return $reload->failed() ? self::FAILED : self::UPDATED;
    }

    /**
     * The file's content as root sees it, or null when it is not there (or
     * cannot be read — either way it has to be written).
     */
    private function read(string $path): ?string
    {
        // Exit 1 is cat's "no such file" — the expected answer on a server
        // that has never had container login, not an error for the log.
        $result = $this->serverOps->run(['cat', $path], $this->context('read'), expectedExitCodes: [1]);

        return $result->ok ? $result->output() : null;
    }

    /**
     * @return array<string, string>
     */
    private function context(string $op): array
    {
        return ['feature' => 'app_shell', 'op' => $op];
    }
}
