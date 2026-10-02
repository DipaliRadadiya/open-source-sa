<?php

namespace App\Actions\Server\Service;

use App\Exceptions\Server\Service\ServiceOperationException;
use App\Services\ActivityLogger;
use App\Services\Server\ConfigTester;
use App\Services\Server\ServiceManager;
use Illuminate\Http\Exceptions\HttpResponseException;
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

        $result = $this->services->run($service['unit'], $action);

        if ($result->failed()) {
            throw new ServiceOperationException($result->reference);
        }

        $this->activityLogger->log('service.'.self::VERBS[$action], null, ['service' => $service['label']]);

        return $this->services->describe($service);
    }

    /**
     * Services with no config test are not held back: there is nothing to ask.
     */
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
