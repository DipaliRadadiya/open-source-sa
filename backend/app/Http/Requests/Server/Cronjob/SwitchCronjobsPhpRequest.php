<?php

namespace App\Http\Requests\Server\Cronjob;

use App\Models\Application;
use App\Models\Cronjob;
use App\Services\Server\Cron\CronPhpBinary;
use App\Services\Server\Runtimes\PhpRuntime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Switch the PHP that some of a site's cron jobs run on — only the ones the
 * user ticked, and only to a version that is installed.
 */
class SwitchCronjobsPhpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('cronjob') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            'cronjob_ids' => ['required', 'array', 'min:1', 'max:100'],
            'cronjob_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $version = (string) $this->input('version');

                // A cron pointed at a PHP that is not there fails on every
                // tick, silently.
                if (! app(PhpRuntime::class)->installed($version)) {
                    $validator->errors()->add('version', __('errors/php.not_installed', ['version' => $version]));

                    return;
                }

                /** @var Application $application */
                $application = $this->route('application');
                $ids = array_map('intval', (array) $this->input('cronjob_ids'));
                $jobs = Cronjob::query()->whereIn('id', $ids)->get()->keyBy('id');
                $binary = app(CronPhpBinary::class);

                foreach ($ids as $i => $id) {
                    $job = $jobs->get($id);

                    // Another site's job, or a server-level one, is not this
                    // site's to change — and is reported as not found rather
                    // than as someone else's.
                    if ($job === null || (int) $job->application_id !== (int) $application->id) {
                        $validator->errors()->add("cronjob_ids.{$i}", __('errors/cronjob.switch_not_this_site'));

                        continue;
                    }

                    $switched = $binary->switchTo((string) $job->command, $version);

                    if ($switched === null) {
                        $validator->errors()->add("cronjob_ids.{$i}", __('errors/cronjob.switch_unknown_php', ['name' => $job->name]));
                    } elseif (strlen($switched) > 1000) {
                        // The same limit an edit has; an OpenLiteSpeed path is
                        // longer than the one it replaces.
                        $validator->errors()->add("cronjob_ids.{$i}", __('errors/cronjob.switch_too_long', ['name' => $job->name]));
                    }
                }
            },
        ];
    }
}
