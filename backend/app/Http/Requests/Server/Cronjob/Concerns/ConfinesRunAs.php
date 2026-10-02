<?php

namespace App\Http\Requests\Server\Cronjob\Concerns;

use App\Models\Cronjob;
use App\Models\SystemUser;
use Illuminate\Validation\Validator;

/**
 * Who a cron job may run as.
 *
 * Bug #16: typing `root` in "Runs as" gave anyone allowed to manage cron
 * jobs a root shell on the server. The panel admin keeps what v7 allowed —
 * any account — and jobs moved over from v7 are adopted, not posted here, so
 * they keep running. Everyone else may only pick the panel's System Users.
 */
trait ConfinesRunAs
{
    protected function confineRunAs(Validator $validator, ?Cronjob $cronjob = null): void
    {
        if ($this->user()?->is_admin) {
            return;
        }

        if ($this->filled('system_user_id')) {
            return; // A System User, already checked to exist.
        }

        // What the job will run as after this save: an edit that leaves the
        // account alone keeps the stored one, so changing the command of a
        // root job is caught too.
        $username = $this->has('username') ? $this->input('username') : $cronjob?->username;

        if (! is_string($username) || $username === '') {
            return; // Nothing chosen; the required rules answer that.
        }

        if (! SystemUser::query()->where('username', $username)->exists()) {
            $validator->errors()->add('username', __('errors/cronjob.user_not_allowed'));
        }
    }
}
