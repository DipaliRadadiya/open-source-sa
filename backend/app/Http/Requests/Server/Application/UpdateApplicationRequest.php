<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\AvailablePort;
use App\Rules\SingleLine;
use App\Rules\StartCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Only the things that are safe to change while nothing is provisioned. The
 * site type is not editable — a different type is a different application.
 */
class UpdateApplicationRequest extends FormRequest
{
    /**
     * The most workers one application may ask for.
     *
     * A ceiling rather than the host's core count. `MemoryMax` is enforced per
     * unit and divided across the workers inside it, so an unbounded number
     * turns a memory limit into a number of very small workers, and the box has
     * other applications on it.
     */
    public const MAX_INSTANCES = 16;

    public function authorize(): bool
    {
        return $this->user()?->canManage('application') ?? false;
    }

    /**
     * Clustering needs a script PM2 can fork, and the start command may be
     * changing in this same request.
     *
     * Checked here rather than in a rule on `process_instances`, because the
     * answer depends on another field — and on the stored value when that field
     * is absent. Refused rather than silently ignored: PM2 pointed at something
     * it cannot fork runs one process and reports success, which is exactly how
     * the old panel's users chose four instances and got one.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $instances = (int) $this->input('process_instances', 0);

                if ($instances <= 1) {
                    return;
                }

                $command = $this->has('start_command')
                    ? (string) $this->input('start_command')
                    : (string) $this->route('application')?->start_command;

                $parts = preg_split('/\s+/', trim($command)) ?: [];

                if (basename($parts[0] ?? '') !== 'node' || blank($parts[1] ?? null)) {
                    $validator->errors()->add('process_instances', __('validation.process_instances_entrypoint'));
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // See StoreApplicationRequest: the name is the config filename.
            // See StoreApplicationRequest: the name reaches a systemd unit.
            'name' => ['sometimes', 'string', 'max:255', new SingleLine, Rule::unique('applications', 'name')->ignore($this->route('application'))],
            'domain' => ['sometimes', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/'],
            // See StoreApplicationRequest: this becomes a path used by root.
            'web_root' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\-\/]+$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
            'build_command' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Rebuilding the same repository a different way is an ordinary
            // change of mind, so this is editable — unlike the site type.
            'rendering_type' => ['sometimes', 'string', Rule::in(['php', 'static', 'csr', 'ssr'])],
            // Becomes systemd's ExecStart, which is not a shell — see the rule.
            'start_command' => ['sometimes', 'nullable', 'string', 'max:500', new StartCommand],
            // More than one means the unit runs `pm2-runtime` and forks that
            // many workers. Bounded rather than open: the old panel used PM2's
            // `-i max`, which reads the host's core count, so every application
            // on the box claimed every core and the per-application MemoryMax
            // stopped describing anything.
            'process_instances' => ['sometimes', 'nullable', 'integer', 'between:1,'.self::MAX_INSTANCES],
            // Excluding this application, so saving an unchanged form does
            // not report the port as taken by itself.
            'app_port' => ['sometimes', 'nullable', 'integer', 'between:1024,65535', new AvailablePort($this->route('application'))],
            'branch' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
        ];
    }
}
