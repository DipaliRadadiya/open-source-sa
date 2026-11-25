<?php

namespace App\Contracts;

/**
 * One self-check against the real server (strategy).
 *
 * The test suite fakes the Process facade, so it can pass while the panel cannot run a single
 * privileged command. A check here does the real thing (shell out, read the filesystem, call
 * the live endpoint), so it catches a missing sudoers grant, a differently named service, or
 * a root-created directory the panel cannot write.
 *
 * Every check must be READ-ONLY and cheap: it runs on a production panel, on demand, and must
 * never change the server to find out whether the server works.
 */
interface DoctorCheck
{
    /** Stable machine key (e.g. `privilege`). Also the i18n key. */
    public function key(): string;

    /**
     * Run it.
     *
     * @return array{
     *     status: 'pass'|'warn'|'fail',
     *     detail: string|null,
     *     fix: string|null,
     * }
     *   `detail` is factual and untranslated (a version, a path, a service
     *   name) — it goes in front of an operator, not an end user. `fix` is a
     *   lang key for what to do about a failure, so the advice is translated
     *   while the evidence is not.
     */
    public function run(): array;
}
