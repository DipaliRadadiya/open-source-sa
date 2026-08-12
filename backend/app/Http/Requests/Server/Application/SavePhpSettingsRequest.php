<?php

namespace App\Http\Requests\Server\Application;

use App\Models\ApplicationPhpSettings;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePhpSettingsRequest extends FormRequest
{
    /**
     * Above this a pool is not a tuning decision, it is a mistake. The memory
     * budget warns; this refuses.
     */
    public const MAX_CHILDREN = 100;

    public function authorize(): bool
    {
        return $this->user()?->canManage('app_php') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // `128M`, `1G`, or `-1` for unlimited — PHP's own vocabulary rather
        // than a number of megabytes, so what the user types is what ends up
        // in the file and they can read it back.
        $size = ['sometimes', 'nullable', 'string', 'regex:/^(-1|\d+[KMG]?)$/i', 'max:12'];

        return [
            'php_version' => ['sometimes', 'string', 'max:8'],

            'memory_limit' => $size,
            'upload_max_filesize' => $size,
            'post_max_size' => $size,
            'max_execution_time' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3600'],
            'max_input_time' => ['sometimes', 'nullable', 'integer', 'min:-1', 'max:3600'],
            'max_input_vars' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:100000'],
            'session_gc_maxlifetime' => ['sometimes', 'nullable', 'integer', 'min:60', 'max:604800'],

            'pm_type' => ['sometimes', Rule::in(ApplicationPhpSettings::PM_TYPES)],
            'pm_max_children' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_CHILDREN],
            'pm_max_requests' => ['sometimes', 'integer', 'min:0', 'max:100000'],

            'open_basedir_enabled' => ['sometimes', 'boolean'],
            'open_basedir_paths' => [
                'sometimes', 'nullable', 'string', 'max:2000',
                function (string $attribute, mixed $value, Closure $fail) {
                    foreach (preg_split('/[:\n,]+/', (string) $value) ?: [] as $path) {
                        $path = trim($path);

                        if ($path === '') {
                            continue;
                        }

                        // Absolute only. A relative entry is resolved against
                        // the worker's working directory, which is not a thing
                        // the user can see or predict.
                        if (! str_starts_with($path, '/')) {
                            $fail(__('php_settings.errors.basedir_absolute', ['path' => $path]));

                            return;
                        }

                        // `/` allows everything, so the pool would say
                        // open_basedir is on while enforcing nothing — the
                        // panel would be reporting a protection it does not
                        // have. Turning the toggle off is the honest way to
                        // get the same result.
                        if (rtrim($path, '/') === '') {
                            $fail(__('php_settings.errors.basedir_root'));

                            return;
                        }

                        if (str_contains($path, '..') || str_contains($path, "\0")) {
                            $fail(__('php_settings.errors.basedir_traversal', ['path' => $path]));

                            return;
                        }
                    }
                },
            ],
            // A comma-separated list of function names and nothing else. This
            // lands in the pool file verbatim, so anything that is not a
            // function name has no business being here.
            'disable_functions' => ['sometimes', 'nullable', 'string', 'max:2000', 'regex:/^[A-Za-z0-9_,\s]*$/'],
            'allow_url_fopen' => ['sometimes', 'boolean'],
            'php_timezone' => ['sometimes', 'nullable', 'timezone'],
            'auto_prepend_file' => ['sometimes', 'nullable', 'string', 'max:255', 'not_regex:/\.\./'],

            // The escape hatch, and the only free-text field. Newlines are
            // allowed because it is ini; a `[section]` header is not, because
            // that would silently start a second pool inside this file.
            'additional_directives' => ['sometimes', 'nullable', 'string', 'max:4000', 'not_regex:/^\s*\[/m'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'additional_directives.not_regex' => __('php_settings.errors.no_sections'),
            'disable_functions.regex' => __('php_settings.errors.function_list'),
        ];
    }
}
