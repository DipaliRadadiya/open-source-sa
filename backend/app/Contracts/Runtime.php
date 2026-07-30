<?php

namespace App\Contracts;

/**
 * A language runtime the server can hold several versions of at once.
 *
 * The shape exists because Node and PHP pose the same question — which
 * versions are here, which is the default, install another — and answering it
 * twice in two different ways would give the panel two screens for one idea.
 *
 * The load-bearing method is `versions()['path']`. A systemd unit's
 * `ExecStart=` needs an absolute binary path: it has no shell and does not
 * source a profile, so a version manager that works by mutating `PATH` per
 * shell cannot be used to run a site. Every implementation must be able to
 * say where a given version's binary actually is.
 */
interface Runtime
{
    /** Stable key — `node`, `php`. Also the i18n key and route segment. */
    public function key(): string;

    /**
     * Which tool manages the versions, or how the one present got there.
     * `fnm`, `system` (distro package, unmanaged), `none`.
     */
    public function manager(): string;

    /**
     * Installed versions, newest first.
     *
     * @return array<int, array{version: string, path: string, is_default: bool, source: string}>
     */
    public function versions(): array;

    /** The version bare `node`/`php` resolves to, or null when none is set. */
    public function default(): ?string;

    /**
     * A pre-existing install the panel did not put there — the distro package
     * on a migrated server. Reported so it can be used and never clobbered.
     *
     * @return array{version: string, path: string}|null
     */
    public function system(): ?array;

    /**
     * Versions that could be installed, for the picker.
     *
     * @return array<int, string>
     */
    public function installable(): array;
}
