<?php

namespace App\Services\Server\Docker;

use App\Models\Registry;
use App\Services\Server\ServerOps;
use App\Services\Server\ServerOpsResult;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hands a registry credential to Docker for the length of one command.
 *
 * **Why `docker --config <dir>` and not `docker login`.** Compose runs as root
 * (the `docker` group is root-equivalent, so the site user must not have it), so
 * `docker login` would write `/root/.docker/config.json` — one credential store
 * shared by every site on the box. Store one tenant's token there and every
 * other tenant's site can pull from their private registry. Measured: the
 * compose plugin honours the CLI's `--config` flag, so a credential can be
 * scoped to one pull for one site instead.
 *
 * **Why a hand-written config.json and not `docker login --password-stdin`.**
 * The written file is the thing `login` would produce, and writing it directly
 * is one command instead of two with no daemon round-trip. Measured against
 * both Docker Hub and a self-hosted registry v2: a hand-written basic-auth
 * entry pulls a private image.
 *
 * **Where the file goes, and what must never happen.** A root-owned `0700`
 * directory outside the site's tree. The site user can read their own document
 * root, and the panel keeps them out of the `docker` group precisely so they
 * cannot reach the daemon — writing a registry token into their directory would
 * hand over by file what was withheld by group.
 *
 * The secret reaches the file through **stdin**, never as a command argument:
 * argv is world-readable in `ps` for the lifetime of the process. This mirrors
 * `GitDeployer::writeCredential()`, which learned it first.
 */
class RegistryAuth
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Run something with this registry's credentials available to Docker.
     *
     * The callback is given the config directory to pass as `--config`, or null
     * when there is no registry to authenticate as — which is the common case
     * and not an error. Every public image and all fifteen one-click apps take
     * that branch.
     *
     * `finally`, not a tidy-up at the end of the happy path: a failed pull is
     * exactly when a credential must not be left on disk, and a failed pull is
     * the likeliest outcome the first time somebody configures one.
     *
     * @template TReturn
     *
     * @param  callable(?string): TReturn  $callback
     * @return TReturn
     */
    public function with(?Registry $registry, array $context, callable $callback): mixed
    {
        if ($registry === null || ! $registry->hasCredentials()) {
            return $callback(null);
        }

        $directory = $this->materialise($registry, $context);

        try {
            return $callback($directory);
        } finally {
            $this->discard($directory, $context);
        }
    }

    /**
     * Write the credential into a fresh private directory and return its path.
     *
     * @param  array<string, mixed>  $context
     */
    private function materialise(Registry $registry, array $context): string
    {
        $directory = $this->privateDirectory($context);

        $path = $directory.'/config.json';

        // Through stdin. The token is never an argument to anything.
        $written = $this->serverOps->run(
            ['tee', $path],
            $context + ['op' => 'registry_auth_write'],
            input: $this->document($registry),
        );

        if ($written->failed()) {
            // Tidy up before reporting, or a failed write leaves a directory
            // behind on every attempt.
            $this->discard($directory, $context);

            throw new RegistryAuthFailedException($written->reference);
        }

        $this->serverOps->run(
            ['chmod', '0600', $path],
            $context + ['op' => 'registry_auth_chmod'],
        );

        return $directory;
    }

    /**
     * A fresh directory only root can enter.
     *
     * `mkdir -m 0700` rather than `mkdir` then `chmod`: the two-step version is
     * world-readable for the moment between them, and the whole point of this
     * directory is that nothing else on the box may look inside it.
     *
     * @param  array<string, mixed>  $context
     */
    private function privateDirectory(array $context): string
    {
        $directory = rtrim((string) config('server.docker.registry_credential_dir', sys_get_temp_dir()), '/')
            .'/registry-'.Str::uuid();

        $this->serverOps->run(
            ['mkdir', '-m', '0700', '-p', $directory],
            $context + ['op' => 'registry_auth_dir'],
        );

        return $directory;
    }

    /**
     * The `config.json` Docker reads.
     *
     * `auths.<key>.auth` is base64 of `username:password` — Docker's own format,
     * not an encryption of anything, which is the whole reason this file must
     * live where only root can read it.
     *
     * The key comes from {@see Registry::authKey()} and Docker Hub's is the
     * legacy v1 index URL. That is not pedantry: measured on a real private Hub
     * repository, a file keyed `docker.io` is **silently ignored** and the pull
     * fails with the identical message it gives when no credential exists at
     * all. A wrong key is therefore the hardest possible failure to diagnose,
     * which is why normalising it is the model's job and not the user's.
     */
    private function document(Registry $registry): string
    {
        $auth = base64_encode($registry->username.':'.(string) $registry->configValue('token', ''));

        return (string) json_encode([
            'auths' => [
                $registry->authKey() => ['auth' => $auth],
            ],
        ], JSON_UNESCAPED_SLASHES).PHP_EOL;
    }

    /**
     * Remove the directory and everything in it.
     *
     * Failures here are swallowed deliberately. This runs in a `finally`, so
     * throwing would replace the real error — the one the user needs — with a
     * cleanup error about a temporary file. The operation is already recorded
     * under its own op key for anyone auditing what was left behind.
     *
     * @param  array<string, mixed>  $context
     */
    private function discard(string $directory, array $context): void
    {
        try {
            $this->serverOps->run(
                ['rm', '-rf', $directory],
                $context + ['op' => 'registry_auth_discard'],
            );
        } catch (Throwable) {
            // Intentionally ignored — see above.
        }
    }

    /**
     * Probe a credential by asking the registry, not by parsing it.
     *
     * `docker login` rather than a pull: a token valid for a registry is the
     * question, and answering it with a pull would need a repository name the
     * user has not given yet and would download an image to prove a password.
     *
     * Hub is logged into with **no argument at all**. `docker login docker.io`
     * is accepted, but the bare form is the daemon's own default path and the
     * one that produces the credential shape a Hub pull looks for.
     *
     * @param  array<string, mixed>  $context
     */
    public function probe(Registry $registry, array $context): ServerOpsResult
    {
        // An EMPTY directory, not a materialised one. `login` writes the file
        // itself, so pre-writing it would put the token on disk twice and prove
        // nothing extra — and the second copy would be the one nobody remembered
        // to think about.
        $directory = $this->privateDirectory($context);

        try {
            $command = ['docker', '--config', $directory, 'login', '--username', $registry->username, '--password-stdin'];

            if (! $registry->isDockerHub()) {
                $command[] = $registry->authKey();
            }

            return $this->serverOps->run(
                $command,
                $context + ['op' => 'registry_login'],
                timeout: (int) config('server.docker.login_timeout', 30),
                input: (string) $registry->configValue('token', ''),
            );
        } finally {
            $this->discard($directory, $context);
        }
    }
}
