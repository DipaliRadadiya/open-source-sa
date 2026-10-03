<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use App\Models\Worker;
use App\Rules\SingleLine;
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
                // The name is rendered into the supervisor program block. A
                // newline there is a directive of the caller's choosing in a
                // file the panel writes and supervisord executes — the same
                // hazard the cron rule exists for, one file format over.
                new SingleLine,
                Rule::unique('workers', 'name')
                    ->where('application_id', $application->id)
                    ->ignore($worker),
            ],

            // A bare `binary arg arg` line. supervisord does not run the
            // command through a shell either, so a pipe or a semicolon here
            // would not do what someone writing it expects — it would be
            // passed to the binary as a literal argument. Refusing is clearer
            // than silently running something else, and it keeps the value
            // safe to write into an ini file.
            'command' => ['required', 'string', 'max:500', 'regex:/^[^\n\r;|&`$<>()]+$/'],

            'kind' => ['sometimes', Rule::in([Worker::KIND_QUEUE, Worker::KIND_HORIZON, Worker::KIND_CUSTOM])],
            // `WorkingDirectory=` in the same unit, so the same rule applies:
            // the traversal guard stops it pointing elsewhere, and this stops
            // it being two directives instead of one.
            'directory' => ['sometimes', 'nullable', 'string', 'max:255', 'not_regex:/\.\./', new SingleLine],

            // The account the copies run as. Left empty the panel uses the
            // site's own system user, which is the answer for anything it
            // created; this exists so an adopted block naming another account
            // keeps it. Same character class the system-user rules use — it
            // becomes `user=` in a file supervisord parses.
            'user' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],

            // `stdout_logfile`. Absolute, and no traversal, for the reason the
            // directory rule says: this is a path the panel writes into a
            // config that runs as root.
            'log_file' => ['sometimes', 'nullable', 'string', 'max:255', 'starts_with:/', 'not_regex:/\.\./', new SingleLine],

            // supervisord's own set. Constrained here rather than in the
            // database so adding one is a validation change, not a migration.
            'log_level' => ['sometimes', 'nullable', Rule::in(['critical', 'error', 'warn', 'info', 'debug', 'trace', 'blather'])],

            // Appended verbatim, so a directive here wins over everything the
            // panel wrote. A section header would let it define a *second*
            // program — one the panel does not know about and would never
            // stop — so the one thing it may not contain is `[`.
            'extra_config' => ['sometimes', 'nullable', 'string', 'max:2000', 'not_regex:/\[/'],

            'auto_start' => ['sometimes', 'boolean'],
            'processes' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PROCESSES],
            'stop_wait_seconds' => ['sometimes', 'integer', 'min:1', 'max:'.Worker::MAX_STOP_WAIT],
            'auto_restart' => ['sometimes', 'boolean'],
            'restart_on_deploy' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->confine($validator));

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

    /**
     * Keys in Extra config that change who runs what, or where root writes.
     */
    private const CONFINED_KEYS = ['user', 'command', 'directory', 'stdout_logfile', 'stderr_logfile'];

    /**
     * A worker is a process supervisord starts as root and hands to `user`,
     * and whose log file root opens. Bug #72: anyone allowed to manage
     * workers could run one as root, or have root write anywhere.
     *
     * The panel admin keeps what v7 allowed: any account, any path. Workers
     * moved over from v7 are adopted, not posted here, so they keep working.
     * Everyone else is held to the site's own account and folders.
     */
    private function confine(Validator $validator): void
    {
        /** @var Application $application */
        $application = $this->route('application');
        /** @var Worker|null $worker */
        $worker = $this->route('worker');
        $extra = (string) $this->input('extra_config', '');

        // Not even for the admin: the screen shows the "Run as" field, and a
        // `user=` underneath it would make that field lie.
        if (preg_match('/^\s*user\s*=/mi', $extra) === 1) {
            $validator->errors()->add('extra_config', __('worker.errors.extra_config_user'));
        }

        if ($this->user()?->is_admin) {
            return;
        }

        $siteUser = (string) $application->systemUser?->username;
        // What the worker will run as after this save: a request that leaves
        // the field out keeps the stored one, so editing a root worker's
        // command is caught too.
        $user = $this->has('user') ? $this->input('user') : $worker?->user;

        if ($user !== null && $user !== '' && $user !== $siteUser) {
            $validator->errors()->add('user', __('worker.errors.user_not_allowed', ['user' => $siteUser]));
        }

        $home = rtrim((string) $application->systemUser?->home_path, '/');
        $directory = $this->has('directory') ? $this->input('directory') : $worker?->directory;

        if ($directory !== null && $directory !== '' && ($home === '' || ! str_starts_with(rtrim((string) $directory, '/').'/', $home.'/'))) {
            $validator->errors()->add('directory', __('worker.errors.directory_outside_home', ['home' => $home]));
        }

        // Only the site's own logs folder. It is root-owned, so the site user
        // cannot plant a symlink there for root to follow; their home is not.
        $logFile = $this->has('log_file') ? $this->input('log_file') : $worker?->log_file;

        if ($logFile !== null && $logFile !== '' && dirname((string) $logFile) !== $application->logsPath()) {
            $validator->errors()->add('log_file', __('worker.errors.log_outside_logs', ['path' => $application->logsPath()]));
        }

        if (preg_match('/^\s*('.implode('|', self::CONFINED_KEYS).')\s*=/mi', $extra, $match) === 1) {
            $validator->errors()->add('extra_config', __('worker.errors.extra_config_key', ['key' => strtolower($match[1])]));
        }
    }
}
