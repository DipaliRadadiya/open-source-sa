<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use App\Models\Worker;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveWorkerRequest extends FormRequest
{
    /** More than this and the box is the problem, not the queue depth. */
    public const MAX_PROCESSES = 16;

    public function authorize(): bool
    {
        return $this->user()?->canManage('app_worker') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Application $application */
        $application = $this->route('application');
        $worker = $this->route('worker');

        return [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('workers', 'name')
                    ->where('application_id', $application->id)
                    ->ignore($worker),
            ],

            // A bare `binary arg arg` line. systemd execs ExecStart directly
            // rather than through a shell, so a pipe or a semicolon here would
            // not do what someone writing it expects — it would be passed to
            // the binary as a literal argument. Refusing is clearer than
            // silently running something else.
            'command' => ['required', 'string', 'max:500', 'regex:/^[^\n\r;|&`$<>()]+$/'],

            'kind' => ['sometimes', Rule::in([Worker::KIND_QUEUE, Worker::KIND_HORIZON, Worker::KIND_CUSTOM])],
            'directory' => ['sometimes', 'nullable', 'string', 'max:255', 'not_regex:/\.\./'],
            'processes' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PROCESSES],
            'stop_wait_seconds' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'auto_restart' => ['sometimes', 'boolean'],
            'restart_on_deploy' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Application $application */
            $application = $this->route('application');
            $kind = $this->string('kind')->value() ?: Worker::KIND_CUSTOM;

            if (! in_array($kind, [Worker::KIND_QUEUE, Worker::KIND_HORIZON], true)) {
                return;
            }

            $opposite = $kind === Worker::KIND_HORIZON ? Worker::KIND_QUEUE : Worker::KIND_HORIZON;

            $exists = Worker::query()
                ->where('application_id', $application->id)
                ->where('kind', $opposite)
                ->when($this->route('worker'), fn ($query, $worker) => $query->whereKeyNot($worker->id))
                ->exists();

            if ($exists) {
                // Horizon supervises its own queue workers. Running both means
                // every job is picked up twice, and neither tool can see the
                // other — so the panel is the only thing in a position to say
                // so, and it will not be obvious from either one's output.
                $validator->errors()->add('kind', __('worker.errors.queue_conflict'));
            }
        });
    }
}
