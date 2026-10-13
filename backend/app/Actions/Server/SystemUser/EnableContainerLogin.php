<?php

namespace App\Actions\Server\SystemUser;

use App\Enums\LoginShell;
use App\Exceptions\Server\SystemUser\ContainerLoginFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\AccountLock;
use App\Services\Server\AppShell\AppShellFiles;
use App\Services\Server\AppShell\AppShellInstaller;
use App\Services\Server\AppShell\AppShellMap;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turn container login on for a Docker app's owner (RC-28).
 *
 * After this, the user's SSH login opens their app's container: no sudo grant,
 * no docker group, one password prompt or none with a key. It reuses the
 * System Users actions for everything they already do — the key, the password,
 * the shell, the SSH toggle — so this switch and that screen cannot disagree
 * about what "SSH access on" means.
 *
 * Order matters, and is chosen so that no step leaves the user with more
 * access than they should have:
 *
 *  1. key / password / shell, which grant nothing on their own;
 *  2. the server pieces and the map, so the first login already finds them;
 *  3. the group — from here sshd forces the user into the container;
 *  4. a check that sshd really does (it asks sshd for this user); if not, the
 *     group is taken away again before SSH is ever switched on;
 *  5. SSH access, through the same path as the System Users toggle.
 */
class EnableContainerLogin
{
    public function __construct(
        private ServerOps $serverOps,
        private AccountLock $accountLock,
        private AppShellFiles $files,
        private AppShellInstaller $installer,
        private AppShellMap $map,
        private AddSshKey $addSshKey,
        private SetSystemUserPassword $setPassword,
        private ChangeShell $changeShell,
        private ToggleSshAccess $toggleSshAccess,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array{ssh_key?: string|null, password?: string|null}  $data
     *
     * @throws ContainerLoginFailedException
     * @throws ValidationException when sshd on this server would not apply it
     */
    public function execute(SystemUser $systemUser, array $data): void
    {
        if (filled($data['ssh_key'] ?? null)) {
            $this->addSshKey->execute($systemUser, [
                'name' => __('docker.container_login.key_name'),
                'public_key' => (string) $data['ssh_key'],
            ]);
        }

        if (filled($data['password'] ?? null)) {
            $this->setPassword->execute($systemUser, (string) $data['password']);
        }

        // sshd runs the forced command through the user's own shell, and
        // nologin refuses to run anything. The forced command decides what
        // happens either way, so a real shell gives them nothing more.
        if (in_array($systemUser->shell, [LoginShell::NoLogin->value, LoginShell::FalseShell->value, '/usr/bin/false', '/sbin/nologin'], true)) {
            $this->changeShell->execute($systemUser, LoginShell::Bash->value);
        }

        $installed = $this->installer->ensure();

        if (in_array(AppShellInstaller::FAILED, $installed, true) || $this->map->write() === null) {
            throw $this->failure('install', $systemUser, ['parts' => $installed]);
        }

        $this->accountLock->run(function () use ($systemUser): void {
            $added = $this->serverOps->run(
                ['usermod', '-aG', $this->files->group(), $systemUser->username],
                ['feature' => 'app_shell', 'op' => 'login.enable', 'system_user' => $systemUser->username],
            );

            if ($added->failed()) {
                throw new ContainerLoginFailedException($added->reference);
            }
        });

        if (! $this->installer->enforcedFor($systemUser->username)) {
            $this->removeFromGroup($systemUser);

            throw ValidationException::withMessages([
                'container_login' => [__('errors/system-user.container_login_unsupported')],
            ]);
        }

        if (! $systemUser->ssh_access) {
            $this->toggleSshAccess->execute($systemUser, true);
        }

        $systemUser->update(['container_login' => true]);

        $this->activityLogger->log('system_user.container_login_enabled', $systemUser, [
            'username' => $systemUser->username,
        ]);
    }

    private function removeFromGroup(SystemUser $systemUser): void
    {
        $this->accountLock->run(fn () => $this->serverOps->run(
            ['gpasswd', '-d', $systemUser->username, $this->files->group()],
            ['feature' => 'app_shell', 'op' => 'login.rollback', 'system_user' => $systemUser->username],
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function failure(string $op, SystemUser $systemUser, array $context): ContainerLoginFailedException
    {
        $reference = (string) Str::uuid();

        Log::channel('server-ops')->error('container login could not be turned on', [
            'reference' => $reference, 'feature' => 'app_shell', 'op' => $op,
            'system_user' => $systemUser->username, ...$context,
        ]);

        return new ContainerLoginFailedException($reference);
    }
}
