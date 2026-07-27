<?php

namespace App\Actions\Server\Service;

use App\Exceptions\Server\Service\ServiceOperationException;
use App\Services\ActivityLogger;
use App\Services\Server\ServiceManager;
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

    public function __construct(
        private ServiceManager $services,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(string $key, string $action): array
    {
        $service = $this->services->find($key);

        // Unknown key, or a managed unit that isn't installed on this box.
        if (! $service || ! $this->services->describe($service)) {
            abort(404, __('errors/service.not_found'));
        }

        // Self-DoS guard: the panel's own web server + php-fpm can't be
        // stopped or disabled.
        if ($this->services->isProtected($service['unit']) && in_array($action, ['stop', 'disable'], true)) {
            throw ValidationException::withMessages([
                'action' => [__('errors/service.protected')],
            ]);
        }

        $result = $this->services->run($service['unit'], $action);

        if ($result->failed()) {
            throw new ServiceOperationException($result->reference);
        }

        $this->activityLogger->log('service.'.self::VERBS[$action], null, ['service' => $service['label']]);

        return $this->services->describe($service);
    }
}
