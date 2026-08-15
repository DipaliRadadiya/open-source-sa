<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;

/**
 * A filesystem command behind a site's release layout failed.
 *
 * Its own type so provisioning can tell "the directory tree could not be made"
 * apart from anything else that goes wrong while a site is being set up, and
 * report the step the user can act on rather than the one that happened to
 * fail second.
 */
class ReleaseOperationException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/application.release_failed';
    }
}
