<?php

namespace App\Services\Server\Docker\Images;

use App\Models\Registry;
use App\Support\RemoteHost;

/**
 * An image reference taken apart the way Docker takes it apart.
 *
 * The rules are Docker's own (distribution/reference), not a guess at them,
 * because the panel and `docker pull` must agree on which registry a name
 * means. A reference that looked like Hub to one and like `memos.example` to
 * the other would inspect one image and deploy another:
 *
 *  - The first path component is a registry only if it contains a `.` or a
 *    `:`, or is `localhost`. So `usememos/memos` is Hub and `ghcr.io/x/y` is
 *    not — and `myhost/app` is Hub, which is what Docker does too.
 *  - A single-component Hub name lives under `library/`.
 *  - No tag and no digest means `latest`.
 *  - Repository paths are lowercase. Docker refuses an uppercase one outright,
 *    so accepting it here would inspect an image nobody can deploy.
 */
final class ImageReference
{
    public const HUB = 'docker.io';

    private const HUB_ALIASES = ['docker.io', 'index.docker.io', 'registry-1.docker.io'];

    private function __construct(
        public readonly string $registry,
        public readonly string $repository,
        public readonly ?string $tag,
        public readonly ?string $digest,
    ) {}

    /**
     * The reference, or null when it is not one Docker would accept.
     */
    public static function parse(string $value): ?self
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > 255
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/:@-]*$/D', $value) !== 1) {
            return null;
        }

        $digest = null;

        if (str_contains($value, '@')) {
            [$value, $digest] = explode('@', $value, 2);

            if (preg_match('/^sha256:[a-f0-9]{64}$/D', $digest) !== 1) {
                return null;
            }
        }

        // The tag is whatever follows the last `:` AFTER the last `/` — the
        // `:` in `registry.example.com:5000/app` is a port, not a tag.
        $tag = null;
        $slash = strrpos($value, '/');
        $colon = strrpos($value, ':');

        if ($colon !== false && ($slash === false || $colon > $slash)) {
            $tag = substr($value, $colon + 1);
            $value = substr($value, 0, $colon);

            if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/D', $tag) !== 1) {
                return null;
            }
        }

        $parts = explode('/', $value);
        $registry = self::HUB;

        if (count($parts) > 1 && (str_contains($parts[0], '.') || str_contains($parts[0], ':') || $parts[0] === 'localhost')) {
            $registry = strtolower(array_shift($parts));
        }

        // The host is connected to, so it is read the way every other host
        // the panel connects to is: `0x7f.0.0.1` and `127.0x1` are loopback to
        // libcurl and a name to the resolver, which finds nothing and so
        // checks nothing. Refused here as not a reference at all.
        if ($registry !== self::HUB && RemoteHost::isUninterpretable((string) preg_replace('/:\d{1,5}$/D', '', $registry))) {
            return null;
        }

        if (in_array($registry, self::HUB_ALIASES, true)) {
            $registry = self::HUB;
        }

        if ($registry === self::HUB && count($parts) === 1) {
            array_unshift($parts, 'library');
        }

        foreach ($parts as $part) {
            if (preg_match('/^[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*$/D', $part) !== 1) {
                return null;
            }
        }

        if ($tag === null && $digest === null) {
            $tag = 'latest';
        }

        return new self($registry, implode('/', $parts), $tag, $digest);
    }

    public function isDockerHub(): bool
    {
        return $this->registry === self::HUB;
    }

    /**
     * The host the registry API is spoken to.
     *
     * `lscr.io` is LinuxServer's vanity name for images that live on GHCR: it
     * answers a manifest request with GHCR's own token challenge, so going
     * straight to GHCR is the same answer with one round trip fewer.
     */
    public function apiHost(): string
    {
        return match ($this->registry) {
            self::HUB => 'registry-1.docker.io',
            'lscr.io' => 'ghcr.io',
            default => $this->registry,
        };
    }

    /**
     * What a manifest is asked for by: the digest when pinned, else the tag.
     */
    public function manifestReference(): string
    {
        return $this->digest ?? (string) $this->tag;
    }

    /**
     * The repository as a user writes it, without a tag.
     *
     * Hub's `library/` prefix is taken back off: `nginx` is the name everybody
     * types and the name the compose file will carry.
     */
    public function name(): string
    {
        if ($this->isDockerHub()) {
            return str_starts_with($this->repository, 'library/')
                ? substr($this->repository, 8)
                : $this->repository;
        }

        return $this->registry.'/'.$this->repository;
    }

    /**
     * The full reference, normalised — what gets echoed back and deployed.
     */
    public function full(): string
    {
        return $this->name()
            .($this->tag !== null ? ':'.$this->tag : '')
            .($this->digest !== null ? '@'.$this->digest : '');
    }

    /**
     * The last path component: `memos` for `usememos/memos`.
     */
    public function shortName(): string
    {
        $parts = explode('/', $this->repository);

        return (string) end($parts);
    }

    /**
     * Is this stored credential for this image's registry?
     *
     * A credential is only ever sent to the registry it was saved for. Sending
     * a GHCR token to whichever host a user typed would hand it to that host.
     */
    public function matches(Registry $registry): bool
    {
        if ($registry->isDockerHub()) {
            return $this->isDockerHub();
        }

        return $registry->authKey() === $this->registry;
    }
}
