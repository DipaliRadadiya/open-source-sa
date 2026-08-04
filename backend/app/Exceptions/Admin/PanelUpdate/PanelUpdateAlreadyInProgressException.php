<?php

namespace App\Exceptions\Admin\PanelUpdate;

use App\Exceptions\Admin\AdminOperationException;

/**
 * The admin clicked "Update" while another panel update was already in
 * flight. Carries a reference the user can quote to support; the raw "row
 * exists" detail stays in the log.
 *
 * Maps to 409 Conflict — the request is well-formed, the user is authorized,
 * but the current state of the system refuses another one. Using 409 (not
 * 429) makes it clear that retrying immediately won't help, because the
 * thing refusing is an in-progress row, not a rate limit.
 */
class PanelUpdateAlreadyInProgressException extends AdminOperationException
{
    protected function messageKey(): string
    {
        return 'errors/panel_update.already_in_progress';
    }

    public function status(): int
    {
        return 409;
    }
}
