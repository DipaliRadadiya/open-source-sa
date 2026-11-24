<?php

namespace App\Actions\Server\Service;

use App\Exceptions\Server\Service\ServiceOperationException;
use App\Services\ActivityLogger;
use App\Services\Server\ConfigTester;
use App\Services\Server\ServiceManager;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RunServiceAction
{
    private const VERBS = [
        'start' => 'started',
        'stop' => 'stopped',
        'restart' => 'restarted',
        'reload' => 'reloaded',
        'enable' => 'enabled',
        'disable' => 'disabled',
    ];

    /**
     * Actions that load the configuration from disk. Restarting nginx over a
     * broken config does not keep the old one running — it stops nginx, fails
     * to start it, and takes every site and the panel itself down with it.
     */
    private const TESTED_ACTIONS = ['start', 'restart', 'reload'];

    /**
     * Actions that take down, for a moment, the very process answering this
     * request when the unit is the web server or PHP-FPM the panel runs on.
     */
    private const DEFERRED_ACTIONS = ['restart', 'reload'];

    public function __construct(
        private ServiceManager $services,
        private ActivityLogger $activityLogger,
        private ConfigTester $tester,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $key, string $action): array
    {
        $service = $this->services->find($key);
        $description = $service ? $this->services->describe($service) : null;

        // Unknown key, or a managed unit that isn't installed on this box.
        if (! $service || ! $description) {
            abort(404, __('errors/service.not_found'));
        }

        // Enforce the same live per-service action list returned to clients.
        // This covers protected services and units that cannot reload.
        if (! in_array($action, $description['actions'], true)) {
            throw ValidationException::withMessages([
                'action' => [__('validation.in', ['attribute' => 'action'])],
            ]);
        }

        if (in_array($action, self::TESTED_ACTIONS, true)) {
            $this->refuseBrokenConfig($service);
        }

        // The panel's own web server or PHP: restarting it inside the request
        // killed the connection carrying the request, so the caller (Central
        // above all) got an error for a restart that worked (QA, 2026-11-24).
        // The config test above has already passed; the answer goes out
        // first and the restart runs right after it is sent.
        if (in_array($action, self::DEFERRED_ACTIONS, true) && $this->services->servesPanel($service['unit'])) {
            App::terminating(fn () => $this->runAfterResponse($service, $action));

            return [...$this->services->describe($service), 'scheduled' => $action];
        }

        $result = $this->services->runService($service, $action);

        if ($result->failed()) {
            throw new ServiceOperationException($result->reference);
        }

        $this->activityLogger->log('service.'.self::VERBS[$action], null, ['service' => $service['label']]);

        return $this->services->describe($service);
    }

    /**
     * Services with no config test are not held back: there is nothing to ask.
     */
    /**
     * The deferred half: nobody is waiting for the answer any more, so a
     * failure goes to the log with a reference rather than to a response.
     *
     * @param  array<string, mixed>  $service
     */
    private function runAfterResponse(array $service, string $action): void
    {
        $result = $this->services->runService($service, $action);

        if ($result->failed()) {
            Log::channel('server-ops')->error('deferred service action failed', [
                'feature' => 'service', 'op' => $action, 'unit' => $service['unit'], 'reference' => $result->reference,
            ]);

            return;
        }

        $this->activityLogger->log('service.'.self::VERBS[$action], null, ['service' => $service['label']]);
    }

    private function refuseBrokenConfig(array $service): void
    {
        $test = $this->tester->test($service['key']);

        if ($test === null || $test['ok']) {
            return;
        }

        $message = __('errors/service.config_invalid', [
            'service' => $service['label'],
        ]);

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => ['action' => [$message]],
            'config_test' => $test,
        ], 422));
    }
}
