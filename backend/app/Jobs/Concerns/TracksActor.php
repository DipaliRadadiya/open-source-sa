<?php

namespace App\Jobs\Concerns;

use App\Models\User;

/**
 * Carries the user who dispatched a job through to the activity log.
 *
 * A queue worker has no authenticated user, so `ActivityLogger` defaulting to
 * `Auth::user()` records null — and every install and deploy read as though
 * nobody had done it. The person is known at dispatch; this keeps hold of them.
 *
 * The **id** travels, not the model. `Queueable` serializes Eloquent models and
 * re-fetches them when the job runs, so a user deleted between clicking the
 * button and the worker picking the job up would kill the job outright with a
 * ModelNotFoundException — losing the install, not just the attribution. An id
 * resolved with `find()` simply comes back null.
 *
 * Null is a legitimate value here and must stay legitimate: a deploy triggered
 * by a git webhook has no user, and reads as System. That is the whole reason
 * for not inventing an actor when one is missing.
 */
trait TracksActor
{
    /**
     * The dispatching user, or null when there wasn't one (a webhook) or they
     * have since been deleted. Each job takes `?int $actorId` in its
     * constructor; this turns it back into a User at the point of logging.
     */
    protected function actor(): ?User
    {
        return $this->actorId === null ? null : User::find($this->actorId);
    }
}
