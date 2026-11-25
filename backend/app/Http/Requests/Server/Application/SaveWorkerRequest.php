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
                // Rendered into the supervisor program block: a newline would inject a directive.
                new SingleLine,
                Rule::unique('workers', 'name')
                    ->where('application_id', $application->id)
                    ->ignore($worker),
            ],

            // A bare `binary arg arg` line. supervisord runs no shell, so a pipe or semicolon would
            // be passed as a literal argument; refuse it, which also keeps the ini value safe.
            'command' => ['required', 'string', 'max:500', 'regex:/^[^\n\r;|&`$<>()]+$/'],

            'kind' => ['sometimes', Rule::in([Worker::KIND_QUEUE, Worker::KIND_HORIZON, Worker::KIND_CUSTOM])],
            // `WorkingDirectory=` in the same unit: no traversal, and one line only.
            'directory' => ['sometimes', 'nullable', 'string', 'max:255', 'not_regex:/\.\./', new SingleLine],

            // The account the copies run as; empty means the site's own system user. Exists so an
            // adopted block keeps its account. Becomes `user=`, so it uses the system-user character class.
            'user' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],

            // `stdout_logfile`: absolute, no traversal; it is a path in a config that runs as root.
            'log_file' => ['sometimes', 'nullable', 'string', 'max:255', 'starts_with:/', 'not_regex:/\.\./', new SingleLine],

            // supervisord's own set; kept here, not in the database, so adding one needs no migration.
            'log_level' => ['sometimes', 'nullable', Rule::in(['critical', 'error', 'warn', 'info', 'debug', 'trace', 'blather'])],

            // Appended verbatim, so it wins over what the panel wrote. No `[`: a section header
            // would define a second program the panel never stops.
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
                // Horizon supervises its own queue workers; running both picks every job up twice.
                $validator->errors()->add('kind', __('worker.errors.queue_conflict'));
            }
        });
    }

    /**
     * Keys in Extra config that change who runs what, or where root writes.
     */
    private const CONFINED_KEYS = ['user', 'command', 'directory', 'stdout_logfile', 'stderr_logfile'];

    /**
     * supervisord starts a worker as root, hands it to `user` and opens its log file as root, so
     * anyone allowed to manage workers could otherwise run one as root or have root write anywhere.
     *
     * The panel admin may use any account and path. Everyone else is held to the site's own
     * account and folders.
     */
    private function confine(Validator $validator): void
    {
        /** @var Application $application */
        $application = $this->route('application');
        /** @var Worker|null $worker */
        $worker = $this->route('worker');
        $extra = (string) $this->input('extra_config', '');

        // Read the way supervisord reads it; an unreadable value is refused.
        $keys = $this->extraConfigKeys($extra);

        if ($keys === null) {
            $validator->errors()->add('extra_config', __('worker.errors.extra_config_encoding'));
            $keys = ['keys' => [], 'indented' => false];
        }

        // Not even for the admin: a `user=` here would make the "Run as" field lie.
        if (in_array('user', $keys['keys'], true)) {
            $validator->errors()->add('extra_config', __('worker.errors.extra_config_user'));
        }

        // The panel writes its own `environment=` (PATH) and supervisord refuses a program with
        // two, so the worker would never start again. Not even for the admin.
        if (in_array('environment', $keys['keys'], true)) {
            $validator->errors()->add('extra_config', __('worker.errors.extra_config_environment'));
        }

        // An indented line continues the value above it, which for the first line is the panel's
        // own `environment=`: indenting would get past the check above.
        if ($keys['indented'] && ! $validator->errors()->has('extra_config')) {
            $validator->errors()->add('extra_config', __('worker.errors.extra_config_indented'));
        }

        if ($this->user()?->is_admin) {
            return;
        }

        $siteUser = (string) $application->systemUser?->username;
        // What the worker will run as after this save: an omitted field keeps the stored value.
        $user = $this->has('user') ? $this->input('user') : $worker?->user;

        if ($user !== null && $user !== '' && $user !== $siteUser) {
            $validator->errors()->add('user', __('worker.errors.user_not_allowed', ['user' => $siteUser]));
        }

        $home = rtrim((string) $application->systemUser?->home_path, '/');
        $directory = $this->has('directory') ? $this->input('directory') : $worker?->directory;

        if ($directory !== null && $directory !== '' && ($home === '' || ! str_starts_with(rtrim((string) $directory, '/').'/', $home.'/'))) {
            $validator->errors()->add('directory', __('worker.errors.directory_outside_home', ['home' => $home]));
        }

        // Only the site's logs folder: it is root-owned, so the site user cannot plant a symlink
        // there for root to follow.
        $logFile = $this->has('log_file') ? $this->input('log_file') : $worker?->log_file;

        if ($logFile !== null && $logFile !== '' && dirname((string) $logFile) !== $application->logsPath()) {
            $validator->errors()->add('log_file', __('worker.errors.log_outside_logs', ['path' => $application->logsPath()]));
        }

        $confined = array_values(array_intersect($keys['keys'], self::CONFINED_KEYS));

        if ($confined !== []) {
            $validator->errors()->add('extra_config', __('worker.errors.extra_config_key', ['key' => $confined[0]]));
        }
    }

    /**
     * Python's `str.isspace()` set, which configparser strips from a key. `\s` alone misses the
     * non-breaking space, so `user\u{a0}: root` would pass PHP and still be read as `user`.
     */
    private const INI_SPACE = '[\s\x{1C}-\x{1F}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';

    /**
     * The keys supervisord will read out of Extra config, lower-cased, and
     * whether any line is indented. Null when it is not valid UTF-8.
     *
     * Mirrors supervisord's parser (configparser, `strict=False`, last copy of a key wins): `:`
     * delimits as well as `=`, a lone `\r` ends a line, and the key is trimmed of Unicode spaces.
     * Indented lines are read as keys too, which only ever refuses more.
     *
     * @return array{keys: array<int, string>, indented: bool}|null
     */
    private function extraConfigKeys(string $extra): ?array
    {
        if (! mb_check_encoding($extra, 'UTF-8')) {
            return null;
        }

        $keys = [];
        $indented = false;

        // Exactly Python's universal newlines. `\R` also splits on \v and \f, which hides an
        // indent: Python counts a leading \v as one.
        foreach (preg_split('/\r\n|\r|\n/', $extra) ?: [] as $line) {
            $trimmed = (string) preg_replace('/^'.self::INI_SPACE.'+|'.self::INI_SPACE.'+$/u', '', $line);

            // Blank, or a whole-line comment: configparser skips both.
            if ($trimmed === '' || $trimmed[0] === ';' || $trimmed[0] === '#') {
                continue;
            }

            if ($trimmed !== $line && preg_match('/^'.self::INI_SPACE.'/u', $line) === 1) {
                $indented = true;
            }

            $key = preg_split('/[=:]/', $trimmed, 2)[0];
            $keys[] = mb_strtolower((string) preg_replace('/'.self::INI_SPACE.'+$/u', '', $key));
        }

        return ['keys' => $keys, 'indented' => $indented];
    }
}
