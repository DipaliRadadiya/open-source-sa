<?php

namespace App\Actions\Server\Cronjob;

use App\Exceptions\Server\Cronjob\CronjobOperationException;
use App\Models\Cronjob;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\CrontabManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCronjob
{
    public function __construct(
        private CrontabManager $crontab,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array{name: string, command: string, expression: string, system_user_id?: int|null, username?: string|null, active?: bool}  $data
     */
    public function execute(array $data): Cronjob
    {
        $systemUser = isset($data['system_user_id']) ? SystemUser::find($data['system_user_id']) : null;
        $username = $systemUser?->username ?? ($data['username'] ?? null);

        if ($username === null || ! $this->crontab->userExists($username)) {
            throw ValidationException::withMessages([
                'username' => [__('errors/cronjob.invalid_user')],
            ]);
        }

        return DB::transaction(function () use ($data, $systemUser, $username) {
            $cronjob = Cronjob::create([
                'name' => $data['name'],
                'username' => $username,
                'system_user_id' => $systemUser?->id,
                'command' => $data['command'],
                'expression' => $data['expression'],
                'active' => $data['active'] ?? true,
            ]);

            // Only active jobs are materialised on disk. A failed write aborts
            // the transaction so we never persist a row without its cron.d file.
            if ($cronjob->active) {
                $result = $this->crontab->write($cronjob);

                if ($result->failed()) {
                    throw new CronjobOperationException($result->reference);
                }
            }

            $this->activityLogger->log('cronjob.created', $cronjob, ['name' => $cronjob->name]);

            return $cronjob;
        });
    }
}
