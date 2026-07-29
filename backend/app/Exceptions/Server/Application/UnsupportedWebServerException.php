<?php

namespace App\Exceptions\Server\Application;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The server runs a web server the panel cannot write config for (or none was
 * detected). Refusing is the right answer: guessing at a syntax we do not
 * support would produce a config that fails its own test at best, and takes
 * every site on the box down at worst.
 */
class UnsupportedWebServerException extends Exception
{
    public function __construct(public readonly ?string $webServer) {}

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __('errors/application.unsupported_web_server', [
                'web_server' => $this->webServer ?: __('errors/application.no_web_server'),
            ]),
        ], 422);
    }
}
