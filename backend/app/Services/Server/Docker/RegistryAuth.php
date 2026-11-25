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
 * Uses `docker --config <dir>`, not `docker login`: Compose runs as root, so `docker login`
 * would write `/root/.docker/config.json`, one credential store shared by every site on the
 * box. A per-command config directory scopes the credential to one pull for one site.
 *
 * The config.json is written by hand (what `login` would produce), without a daemon round-trip.
 *
 * It goes in a root-owned `0700` directory outside the site's tree. Never write the token
 * into the site user's directory: that hands over by file what the `docker` group withholds.
 *
 * The secret reaches the file through stdin, never as a command argument: argv is
 * world-readable in `ps`. Mirrors `GitDeployer::writeCredential()`.
 */
class RegistryAuth
{
    public function __construct(private ServerOps $serverOps) {}

    /**
     * Run something with this registry's credentials available to Docker.
     *
     * The callback is given the config directory to pass as `--config`, or null when there is
     * no registry to authenticate as (public images; not an error).
     *
     * Cleanup runs in `finally`: a failed pull is when a credential must not stay on disk.
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
            // Tidy up before reporting, or each failed attempt leaves a directory behind.
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
     * `mkdir -m 0700`, not `mkdir` then `chmod`: that would be world-readable in between.
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
     * `auths.<key>.auth` is base64 of `username:password`: not encryption, so the file must
     * stay readable by root only.
     *
     * The key comes from {@see Registry::authKey()}; Docker Hub's is the legacy v1 index URL.
     * A file keyed `docker.io` is silently ignored and the pull fails as if no credential existed.
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
     * Failures are swallowed on purpose: this runs in a `finally`, and throwing would replace
     * the real error. The operation is recorded under its own op key.
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
            // Intentionally ignored; see the docblock.
        }
    }

    /**
     * Probe a credential by asking the registry, not by parsing it.
     *
     * `docker login` rather than a pull: a pull needs a repository name and downloads an image.
     *
     * Hub is logged into with no registry argument: the bare form produces the credential
     * shape a Hub pull looks for.
     *
     * @param  array<string, mixed>  $context
     */
    public function probe(Registry $registry, array $context): ServerOpsResult
    {
        // An empty directory: `login` writes the file itself, and pre-writing it would put the
        // token on disk twice.
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
