<?php

namespace App\Services\Server\BuildTools;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Services\Server\ServerOps;

/**
 * The compiler toolchain npm reaches for when a package has no prebuilt binary.
 *
 * npm ships prebuilt binaries per Node ABI, and only for the versions the
 * maintainer chose to build for. Everything else falls back to compiling from
 * source through node-gyp — which needs `make` and a C/C++ compiler. On a
 * server without them that fallback is not slow, it is fatal: n8n died in
 * node-gyp building isolated-vm on 2026-09-15, and what the user saw was a
 * dependency-resolution error, because npm buries the one useful line under
 * thousands of peer warnings.
 *
 * No DB. Presence is read from the box every time (detect-don't-trust): the
 * packages can be removed by anyone with root, and a remembered "yes" would
 * send a site install into the same wall the flag was supposed to prevent.
 */
class BuildToolsManager
{
    /**
     * The binaries whose absence stops a native build.
     *
     * Checked rather than `dpkg-query build-essential`, because the question
     * is "can this server compile", not "did apt install our metapackage".
     * A server set up by hand, or migrated from another panel, can have a
     * working toolchain and no build-essential entry — and refusing to believe
     * it would reinstall 235 MB to change nothing.
     *
     * Mirrors the needles {@see ProvisioningFailedException}
     * classifies a failed build by, so the thing that reports the problem and
     * the thing that fixes it disagree about nothing.
     *
     * @var array<int, string>
     */
    public const BINARIES = ['make', 'cc', 'g++'];

    public function __construct(private ServerOps $serverOps) {}

    /**
     * Can this server compile a native module?
     *
     * All of them, not any: node-gyp needs a working chain, and a box with
     * `make` but no compiler fails just as completely as one with neither —
     * only later, and with a worse message.
     */
    public function installed(): bool
    {
        foreach (self::BINARIES as $binary) {
            if (! $this->has($binary)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Which of them are missing, for a message that names the gap rather than
     * asserting one.
     *
     * @return array<int, string>
     */
    public function missing(): array
    {
        return array_values(array_filter(
            self::BINARIES,
            fn (string $binary): bool => ! $this->has($binary),
        ));
    }

    private function has(string $binary): bool
    {
        // Array args through ServerOps, never a shell string — the same rule
        // every other command the panel runs follows.
        return $this->serverOps->run(
            ['which', $binary],
            ['feature' => 'build_tools', 'op' => 'detect', 'binary' => $binary],
        )->ok;
    }
}
