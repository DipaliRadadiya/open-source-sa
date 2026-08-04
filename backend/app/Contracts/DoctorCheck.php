<?php

namespace App\Contracts;

/**
 * One self-check against the real server (strategy).
 *
 * These exist because the test suite fakes the Process facade, which means it
 * can pass in full while the panel cannot run a single privileged command —
 * exactly what happened before sudo escalation was added. A check here does
 * the real thing: it shells out, reads the actual filesystem, calls the live
 * endpoint. It is the only class of test that can catch a missing sudoers
 * grant, a service named differently by the installer, or a directory that
 * root created and the panel account cannot write.
 *
 * Every check must be READ-ONLY and cheap. It runs on a production panel, on
 * demand, from an admin screen — it must never change the server to find out
 * whether the server works.
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
