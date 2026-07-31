<?php

namespace App\Contracts;

/**
 * One row on the setup page: a thing the server either has or hasn't.
 *
 * The contract is deliberately about *reporting*, not installing. Each component
 * points at the endpoint that installs it — the ones that already existed for PHP
 * versions, Node versions, fail2ban and database engines. Wrapping those in a
 * second install route would mean two ways to do the same thing, and the second
 * one drifting.
 *
 * **State is always detected, never remembered.** That is the rule that keeps this
 * page honest:
 *
 *  - Someone installs the panel on a server that already runs MariaDB. It shows
 *    as installed on first load, before they click anything — because it is.
 *  - Someone `apt remove`s Redis next month. It goes back to pending, correctly.
 *  - `pending` therefore means "we looked and it is not there", not "we have not
 *    tried yet". There is no bookkeeping to go stale.
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
     * Whether the panel is meaningfully limited without it.
     *
     * Nothing here is *required* — the installer already put the web server, PHP
     * and Node in place, so the panel works the moment it boots. Recommended
     * means "you will want this before your first real site", which is a
     * suggestion the user is free to ignore rather than a gate.
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
     * Returned as data so the frontend renders one component list from one
     * response, rather than hardcoding which button calls which of five
     * pre-existing endpoints.
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
