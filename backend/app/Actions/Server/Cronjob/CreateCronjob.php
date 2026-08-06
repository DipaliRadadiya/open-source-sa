<?php

namespace App\Actions\Server\Cronjob;

use App\Exceptions\Server\Cronjob\CronjobOperationException;
use App\Models\Cronjob;
use App\Models\SystemUser;
use App\Services\ActivityLogger;
use App\Services\Server\CrontabManager;
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

        $cronjob = Cronjob::create([
            'name' => $data['name'],
            'slug' => Cronjob::uniqueSlug($data['name']),
            'username' => $username,
            'system_user_id' => $systemUser?->id,
            'command' => $data['command'],
            'expression' => $data['expression'],
            'active' => $data['active'] ?? true,
        ]);

        // Committed before the file is written, rather than wrapped around it.
        // The row has to exist first — its id goes into the cron.d header — and
        // holding a transaction open across `tee` would block every other write
        // in the panel for the length of that command, which on SQLite means
        // one writer at a time and "database is locked" somewhere unrelated.
        //
        // A failed write is undone by deleting the row we just made. That is
        // weaker than a transaction only in the case of the process dying
        // mid-write; a transaction was never able to un-write the file either,
        // so the guarantee it appeared to give was already partly imaginary.
        if ($cronjob->active) {
            $result = $this->crontab->write($cronjob);

            if ($result->failed()) {
                $cronjob->delete();

                throw new CronjobOperationException($result->reference);
            }
        }

        $this->activityLogger->log('cronjob.created', $cronjob, ['name' => $cronjob->name]);

        return $cronjob;
    }
}
