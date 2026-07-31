<?php

namespace App\Exceptions\Server\Application;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Deploy-on-push cannot be configured as asked. Always the caller's request to
 * fix, never a server fault — so 422 with a message the form can show.
 */
class WebhookConfigurationException extends Exception
{
    private function __construct(private readonly string $messageKey)
    {
        parent::__construct($messageKey);
    }

    public static function notAGitApplication(): self
    {
        return new self('errors/application.webhook_not_a_git_application');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => __($this->messageKey)], 422);
    }
}
