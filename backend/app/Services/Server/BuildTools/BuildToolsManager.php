<?php

namespace App\Services\Server\BuildTools;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Services\Server\ServerOps;

/**
 * The compiler toolchain npm reaches for when a package has no prebuilt binary.
 *
 * npm ships prebuilt binaries only for some Node ABIs; the rest compile from source through
 * node-gyp, which needs `make` and a C/C++ compiler. Without them that is fatal, and npm
 * buries the one useful line under peer warnings.
 *
 * No DB: presence is read from the box every time (detect, don't trust), since anyone with
 * root can remove the packages.
 */
class BuildToolsManager
{
    /**
     * The binaries whose absence stops a native build.
     *
     * Checked instead of `dpkg-query build-essential`: the question is "can this server
     * compile", and a hand-set-up server can have a toolchain without that package.
     *
     * Mirrors the needles {@see ProvisioningFailedException} classifies a failed build by.
     *
     * @var array<int, string>
     */
    public const BINARIES = ['make', 'cc', 'g++'];

    public function __construct(private ServerOps $serverOps) {}

    /**
     * Can this server compile a native module?
     *
     * All of them, not any: node-gyp needs the whole chain.
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
     * Which of them are missing, so a message can name the gap.
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
        // Array args through ServerOps, never a shell string.
        return $this->serverOps->run(
            ['which', $binary],
            ['feature' => 'build_tools', 'op' => 'detect', 'binary' => $binary],
        )->ok;
    }
}
