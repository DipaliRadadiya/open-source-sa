<?php

namespace App\Http\Controllers\API\Server;

use App\Actions\Server\Application\CreateMagicLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\Application\StoreMagicLoginRequest;
use App\Models\Application;
use App\Services\Server\Applications\WordPressMagicLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Both routes are gated by `permission:app_magic_login`, which the middleware
 * also resolves against the site type — so on anything that is not WordPress
 * these 404 rather than 403. "This screen does not exist here" is the true
 * statement; 403 would imply a grant could make it work.
 */
class MagicLoginController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function index(Application $application, WordPressMagicLogin $magicLogin): JsonResponse
    {
        if ($magicLogin->isMultisite($application)) {
            throw ValidationException::withMessages([
                'magic_login' => [__('errors/magic_login.multisite_unsupported')],
            ]);
        }

        return response()->json([
            // No email in the response. The list exists to let an operator pick
            // between accounts, and login plus display name does that; shipping
            // every administrator's address to a screen that does not need it
            // is a needless widening of what this endpoint discloses.
            'administrators' => array_map(fn (array $admin) => [
                'id' => $admin['id'],
                'login' => $admin['login'],
                'name' => $admin['name'],
            ], $magicLogin->administrators($application)),
        ]);
    }

    /**
     * Returns the token itself, once, to the browser that asked for it. It is
     * never stored in the panel — only its hash reaches the site — so there is
     * nothing to fetch again and no second endpoint to read it from.
     *
     * @throws ValidationException
     */
    public function store(Application $application, StoreMagicLoginRequest $request, CreateMagicLogin $action): JsonResponse
    {
        return response()->json([
            'magic_login' => $action->execute(
                $application,
                (int) $request->validated('wp_user_id'),
            ),
        ], 201);
    }
}
