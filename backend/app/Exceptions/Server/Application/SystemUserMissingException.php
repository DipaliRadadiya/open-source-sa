<?php

namespace App\Exceptions\Server\Application;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The application's system user is not in the panel's database.
 *
 * Every path the panel builds for a site starts at that user's home, and every
 * command it runs on the site's files runs as that user — so without one there
 * is nothing safe to do. It used to surface as "Attempt to read property
 * 'username' on null", a 500 on whichever screen asked first.
 *
 * 409: nothing failed and retrying will not help; the record is in a state the
 * operation cannot work with. The site can still be opened and deleted.
 */
class SystemUserMissingException extends Exception
{
    public function __construct(public readonly string $application)
    {
        parent::__construct("Application {$application} has no system user.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __('errors/application.system_user_missing', ['name' => $this->application]),
        ], 409);
    }
}
