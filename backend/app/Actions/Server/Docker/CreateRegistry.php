<?php

namespace App\Actions\Server\Docker;

use App\Models\Registry;
use App\Services\ActivityLogger;

class CreateRegistry
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * @param  array{name: string, registry: string, username: string, token: string}  $data
     */
    public function execute(array $data): Registry
    {
        // The `encrypted:array` cast encrypts on the way in, so the token is
        // handed over in the clear here and never stored that way. Nothing in
        // `$data` may arrive pre-encrypted — see the model.
        $registry = Registry::create([
            'name' => $data['name'],
            'registry' => $data['registry'],
            'username' => $data['username'],
            'config' => ['token' => $data['token']],
        ]);

        // The address and the account, never the token. This row is readable by
        // anyone who can read the activity log, which is a wider audience than
        // the people who can manage Docker.
        $this->activityLogger->log('registry.created', $registry, [
            'name' => $registry->name,
            'registry' => $registry->registry,
            'username' => $registry->username,
        ]);

        return $registry;
    }
}
