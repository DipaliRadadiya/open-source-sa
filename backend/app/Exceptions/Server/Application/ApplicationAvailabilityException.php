<?php

namespace App\Exceptions\Server\Application;

use App\Exceptions\Server\ServerOperationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;

class ApplicationAvailabilityException extends ServerOperationException
{
    /**
     * @param  ?string  $reason  the {@see ProvisioningFailedException::$reason}
     *                           code of the step that refused, when there was one.
     */
    public function __construct(string $reference, public readonly ?string $reason = null)
    {
        parent::__construct($reference);
    }

    protected function messageKey(): string
    {
        return 'errors/application.availability_failed';
    }

    /**
     * The reason, when the step that refused gave one (F3).
     *
     * Resuming a container site re-applies its compose file, and that can be
     * refused for a reason the panel already knows and has already put into
     * words — the container keeps restarting, the registry refused the pull.
     * The generic "failed on the server" threw that away and left a reference
     * only someone with the server-ops log can use; found in Docker QA, where
     * the user had no way to tell "wait a minute" from "something is broken".
     *
     * Only a reason with its own sentence in `application.failure_reason` is
     * shown. Some steps put free text in `reason` rather than a code, and an
     * unknown code would render as its raw key — both fall back to the message
     * this always gave. Additive: `code`, `reference` and the status are what
     * they were; `reason` is a new key a client may branch on.
     */
    public function render(Request $request): JsonResponse
    {
        $response = parent::render($request);
        $key = $this->reason !== null ? 'application.failure_reason.'.$this->reason : null;

        if ($key === null || ! Lang::has($key)) {
            return $response;
        }

        $body = $response->getData(true);
        $body['message'] = __('errors/application.availability_failed_because', ['reason' => __($key)]);
        $body['reason'] = $this->reason;

        return $response->setData($body);
    }
}
