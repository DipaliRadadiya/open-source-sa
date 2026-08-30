<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;

/**
 * The panel could not find out whether an application has a `.env`.
 *
 * Distinct from "it has none". `test -f` exits 1 both for a file that is not
 * there and for a command that never ran — a missing sudoers grant, a home
 * directory the panel cannot traverse — and reading the second as the first
 * gives the user an empty editor for a file that is sitting on disk, full of
 * their settings. This is the one failure the screen must not answer silently.
 */
class EnvironmentOperationException extends ServerOperationException
{
    protected function messageKey(): string
    {
        return 'errors/application.environment_failed';
    }
}
