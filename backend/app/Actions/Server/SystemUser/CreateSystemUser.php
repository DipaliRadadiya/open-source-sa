<?php

namespace App\Actions\Server\SystemUser;

use App\Exceptions\Server\SystemUser\SystemUserCreateFailedException;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\ServerOps;
use Illuminate\Support\Facades\Cache;

class CreateSystemUser
{
    public function __construct(
        private ServerOps $serverOps,
        private AddSshKey $addSshKey,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array{username: string, public_key?: string|null}  $data
     */
    public function execute(array $data): SystemUser
    {
        // App-level lock: stop two concurrent PHP-FPM workers double-running
        // useradd for the same username (a race even though we run locally).
        return Cache::lock('system-user:create:'.$data['username'], 15)->block(5, function () use ($data) {
            $username = $data['username'];
            $homePath = rtrim((string) config('server.home_base'), '/').'/'.$username;

            $result = $this->serverOps->run(
                ['useradd', '-m', '-s', '/bin/bash', $username],
                ['feature' => 'system_user', 'op' => 'create', 'system_user' => $username],
            );

            if ($result->failed()) {
                $this->activityLogger->log('system_user.create_failed', null, ['username' => $username]);
                throw new SystemUserCreateFailedException($result->reference, busy: $result->busy);
            }

            $systemUser = SystemUser::create([
                'username' => $username,
                'home_path' => $homePath,
                'shell' => '/bin/bash',
            ]);

            if (! empty($data['public_key'])) {
                $this->addSshKey->execute($systemUser, ['name' => 'default', 'public_key' => $data['public_key']]);
            }

            $this->activityLogger->log('system_user.created', $systemUser, ['username' => $username]);

            return $systemUser;
        });
    }
}
