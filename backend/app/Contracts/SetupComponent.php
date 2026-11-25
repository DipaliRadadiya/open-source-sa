<?php

namespace App\Contracts;

/**
 * One row on the setup page: a thing the server either has or hasn't.
 *
 * The contract is about *reporting*, not installing: each component points at the existing
 * endpoint that installs it, so there is no second install route to drift.
 *
 * **State is always detected, never remembered.** Something already on the server shows as
 * installed on first load, and one removed later goes back to pending; `pending` means "we
 * looked and it is not there". There is no bookkeeping to go stale.
 */
interface SetupComponent
{
    /** Stable key the frontend switches on: `database`, `php`, `node`, … */
    public function key(): string;

    /**
     * Whether the server has it right now, detected fresh.
     */
    public function installed(): bool;

    /**
     * Whether this row belongs on this server at all.
     *
     * Distinct from `installed()` and `recommended()`: `recommended` only decides whether
     * setup counts as complete, so a row that does not apply must not be rendered at all.
     *
     * Most components apply everywhere; site-facing ones (database engine, extra PHP or Node
     * versions, compiler toolchain) do not on a server that hosts no such sites. Answered from
     * the hosted serving profiles, not a stack name, so a new stack needs no edits here.
     */
    public function applies(): bool;

    /**
     * Whether the panel is meaningfully limited without it.
     *
     * Nothing is *required* (the installer already put the web server, PHP and Node in place);
     * recommended is a suggestion the user may ignore, not a gate.
     */
    public function recommended(): bool;

    /**
     * A short factual detail for the row — a version, a size, a count. Null when
     * there is nothing useful to say.
     */
    public function detail(): ?string;

    /**
     * How to install it: `['method' => 'POST', 'endpoint' => '/api/…']`, or null
     * when the panel cannot install it and the user must do it themselves.
     *
     * Returned as data so the frontend renders one list instead of hardcoding which button
     * calls which endpoint.
     *
     * @return array{method: string, endpoint: string}|null
     */
    public function action(): ?array;

    /**
     * Choices, when the component is a pick-one rather than a yes/no — the
     * database engine being the only one today.
     *
     * @return array<int, array<string, mixed>>
     */
    public function options(): array;
}
