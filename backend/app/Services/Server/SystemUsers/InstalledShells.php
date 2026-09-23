<?php

namespace App\Services\Server\SystemUsers;

use App\Enums\LoginShell;

/**
 * The login shells from `LoginShell` that this server can actually run.
 *
 * The enum is what the panel is willing to offer; this is what the machine
 * has. The two were treated as one, and they are not: zsh is not in a stock
 * Ubuntu image, yet the picker offered it, the API accepted it, and
 * `usermod -s /usr/bin/zsh` succeeds against a path that does not exist —
 * after which the user cannot log in at all. Reproduced on a real server
 * (2026-09-23).
 *
 * Asked of the filesystem directly rather than through ServerOps: the panel
 * runs on the server it manages, a shell has to be world-executable to be
 * anybody's login shell, and a probe per picker render is not worth a
 * subprocess.
 */
class InstalledShells
{
    public function isInstalled(string $path): bool
    {
        return is_file($path) && is_executable($path);
    }

    /** @return array<int, string> */
    public function paths(): array
    {
        return array_values(array_filter(LoginShell::paths(), $this->isInstalled(...)));
    }

    /**
     * `LoginShell::catalog()`, less what is not installed. Hidden rather than
     * flagged, so a picker that renders the list as-is cannot offer one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        return array_values(array_filter(
            LoginShell::catalog(),
            fn (array $shell): bool => $this->isInstalled($shell['value']),
        ));
    }
}
