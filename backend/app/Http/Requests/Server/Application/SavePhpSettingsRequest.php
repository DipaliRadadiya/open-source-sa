<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\InstallStatus;
use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Rules\SupportedPhpVersion;
use App\Services\Applications\SiteTypeManager;
use App\Services\Applications\SiteTypeText;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Php\AdditionalDirectives;
use App\Services\Server\Php\MemoryBudget;
use App\Services\Server\Php\PhpVersionManager;
use App\Services\Server\Php\PoolIsolator;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SavePhpSettingsRequest extends FormRequest
{
    /**
     * Above this a pool is not a tuning decision, it is a mistake. The memory
     * budget warns; this refuses.
     */
    public const MAX_CHILDREN = 100;

    /**
     * The site being saved. Set directly when these rules run on a body that is not this
     * request's own (the v7 extra-conf preview).
     */
    public ?Application $forApplication = null;

    public function target(): mixed
    {
        return $this->forApplication ?? $this->route('application');
    }

    public function authorize(): bool
    {
        return $this->user()?->canManage('app_php') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // `128M`, `1G`, or `-1` for unlimited: PHP's own vocabulary, written to the file as typed.
        $size = ['sometimes', 'nullable', 'string', 'regex:/^(-1|\d+[KMG]?)$/i', 'max:12'];

        return [
            // Checked against what is installed, not just the string's shape (`8.9` would
            // otherwise fail later as an unreadable pool write). Lazy: free if not saved.
            'php_version' => array_merge([
                'sometimes', 'string', 'max:8',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! app(PhpVersionManager::class)->exists((string) $value)) {
                        $fail(__('php_settings.errors.version_not_installed', ['version' => $value]));

                        return;
                    }

                    // The directory exists as soon as apt starts; a pool written mid-install
                    // makes apt's own php-fpm start fail. Wait for the install instead.
                    $install = app(InstallTracker::class)->versions('php')->get((string) $value);

                    if (in_array($install?->status, [InstallStatus::Installing, InstallStatus::Removing], true)) {
                        $fail(__('php_settings.errors.version_busy', ['version' => $value]));
                    }
                },
            ], $this->supportedRangeRules()),

            'memory_limit' => [...$size, $this->memoryLimitRule()],
            'upload_max_filesize' => $size,
            'post_max_size' => $size,
            'max_execution_time' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3600'],
            'max_input_time' => ['sometimes', 'nullable', 'integer', 'min:-1', 'max:3600'],
            'max_input_vars' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:100000'],
            'session_gc_maxlifetime' => ['sometimes', 'nullable', 'integer', 'min:60', 'max:604800'],

            'pm_type' => ['sometimes', 'nullable', Rule::in(ApplicationPhpSettings::PM_TYPES)],
            'pm_max_children' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_CHILDREN],
            'pm_max_requests' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            // Kept from v7 when a site has them; empty = derived from max_children.
            // They must agree with it (checked in after()).
            'pm_start_servers' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_CHILDREN],
            'pm_min_spare_servers' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_CHILDREN],
            'pm_max_spare_servers' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_CHILDREN],
            'pm_process_idle_timeout' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:86400'],
            'pm_max_spawn_rate' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1024'],

            'open_basedir_enabled' => ['sometimes', 'boolean'],
            'open_basedir_paths' => [
                'sometimes', 'nullable', 'string', 'max:2000',
                function (string $attribute, mixed $value, Closure $fail) {
                    foreach (preg_split('/[:\n,]+/', (string) $value) ?: [] as $path) {
                        $path = trim($path);

                        if ($path === '') {
                            continue;
                        }

                        // Absolute only: a relative entry resolves against the worker's cwd.
                        if (! str_starts_with($path, '/')) {
                            $fail(__('php_settings.errors.basedir_absolute', ['path' => $path]));

                            return;
                        }

                        // `/` allows everything: open_basedir would look on while enforcing nothing.
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
            // Function names only: this lands in the pool file verbatim.
            'disable_functions' => ['sometimes', 'nullable', 'string', 'max:2000', 'regex:/^[A-Za-z0-9_,\s]*$/'],
            'allow_url_fopen' => ['sometimes', 'nullable', 'boolean'],
            // `all_with_bc`: plain `timezone` is PHP's canonical list only and refuses zones the
            // form offers (`Etc/UTC`, `Etc/GMT±N`, `EST`) that date.timezone accepts.
            'php_timezone' => ['sometimes', 'nullable', 'timezone:all_with_bc'],
            'auto_prepend_file' => ['sometimes', 'nullable', 'string', 'max:255', 'not_regex:/\.\./', $this->prependInsideSiteRule()],

            // The escape hatch and only free-text field: ini lines, but no `[section]` header
            // (it would start a second pool inside the file). See AdditionalDirectives.
            'additional_directives' => ['sometimes', 'nullable', 'string', 'max:4000', 'not_regex:/^\s*\[/m',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $application = $this->target();
                    $refusal = app(AdditionalDirectives::class)->refusal(
                        (string) $value,
                        // No pool on OpenLiteSpeed: pool settings have nowhere to go.
                        fpm: app(PoolIsolator::class)->supported(),
                        logsPath: $application instanceof Application ? rescue(fn () => $application->logsPath(), null, false) : null,
                    );

                    if ($refusal !== null) {
                        $fail(__('php_settings.errors.'.$refusal['reason'], [
                            'line' => mb_strimwidth($refusal['line'], 0, 80, '…'),
                            'name' => $refusal['name'],
                        ]));
                    }
                },
            ],
        ];
    }

    /** 32M: below this no application the panel installs can start. */
    private const MEMORY_MINIMUM = 32 * 1024 * 1024;

    /**
     * Refuses `-1` (unlimited lets one site take the server's memory) and values above the
     * server's RAM. An unchanged stored value passes, or no other setting could be saved.
     * Unknown RAM (no /proc/meminfo) checks nothing.
     */
    private function memoryLimitRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $value = trim((string) $value);

            if ($value === '' || $value === $this->stored('memory_limit')) {
                return;
            }

            if ($value === '-1') {
                $fail(__('php_settings.errors.memory_unlimited'));

                return;
            }

            // `64` is 64 *bytes* to PHP and `0` is none: either takes the site down. Nothing
            // fits below 32M.
            if (ApplicationPhpSettings::toBytes($value) < self::MEMORY_MINIMUM) {
                $fail(__('php_settings.errors.memory_too_low', [
                    'suggestion' => ctype_digit($value) && (int) $value > 0 ? $value.'M' : '128M',
                ]));

                return;
            }

            $ram = app(MemoryBudget::class)->totalMemoryBytes();

            if ($ram > 0 && ApplicationPhpSettings::toBytes($value) > $ram) {
                $fail(__('php_settings.errors.memory_over_ram', ['ram' => Number::fileSize($ram, maxPrecision: 1)]));
            }
        };
    }

    /**
     * A post_max_size below upload_max_filesize makes uploads between the two fail, and PHP
     * reports it only as an empty `$_POST`.
     *
     * Compared as they will be after the save (an unsent field keeps its stored value).
     * `0` and `-1` mean no limit and are left alone.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [$this->spareServersAgree(...), function (Validator $validator): void {
            if (! $this->hasAny(['post_max_size', 'upload_max_filesize'])
                || $validator->errors()->hasAny(['post_max_size', 'upload_max_filesize'])) {
                return;
            }

            $post = trim((string) ($this->input('post_max_size') ?? $this->stored('post_max_size', effective: true)));
            $upload = trim((string) ($this->input('upload_max_filesize') ?? $this->stored('upload_max_filesize', effective: true)));

            if ($post === '' || $upload === '' || in_array($post, ['0', '-1'], true) || in_array($upload, ['0', '-1'], true)) {
                return;
            }

            if (ApplicationPhpSettings::toBytes($post) < ApplicationPhpSettings::toBytes($upload)) {
                $validator->errors()->add(
                    $this->has('post_max_size') ? 'post_max_size' : 'upload_max_filesize',
                    __('php_settings.errors.post_below_upload', ['upload' => $upload, 'post' => $post]),
                );
            }
        }];
    }

    /**
     * PHP-FPM refuses to start a dynamic pool unless min_spare <= start <= max_spare <=
     * max_children (fpm_conf.c). All three or none. Compared as they will be after the save.
     */
    private function spareServersAgree(Validator $validator): void
    {
        $keys = ['pm_start_servers', 'pm_min_spare_servers', 'pm_max_spare_servers'];

        if (! $this->hasAny([...$keys, 'pm_max_children']) || $validator->errors()->hasAny([...$keys, 'pm_max_children'])) {
            return;
        }

        $value = fn (string $key): ?int => ($v = $this->has($key) ? $this->input($key) : $this->stored($key)) === null || $v === '' ? null : (int) $v;
        [$start, $min, $max] = array_map($value, $keys);
        $children = (int) ($this->has('pm_max_children') && $this->input('pm_max_children') !== null
            ? $this->input('pm_max_children')
            : $this->stored('pm_max_children', effective: true));

        if ($start === null && $min === null && $max === null) {
            return;
        }

        if ($start === null || $min === null || $max === null || $min > $start || $start > $max || $max > $children) {
            $validator->errors()->add('pm_start_servers', __('php_settings.errors.pm_spare_inconsistent', [
                'min' => $min ?? '-', 'start' => $start ?? '-', 'max' => $max ?? '-', 'children' => $children,
            ]));
        }
    }

    /**
     * Must be an absolute path inside this site's own folder: it runs at the top of every
     * request, so another site's file must not be allowed.
     */
    private function prependInsideSiteRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $value = trim((string) $value);

            if ($value === '' || $value === $this->stored('auto_prepend_file')) {
                return;
            }

            $application = $this->target();
            $root = $application instanceof Application ? rescue(fn () => $application->rootPath(), null, false) : null;

            if ($root === null || str_contains($value, "\0") || ! str_starts_with($value, rtrim($root, '/').'/')) {
                $fail(__('php_settings.errors.prepend_outside_site', ['root' => (string) $root]));
            }
        };
    }

    /**
     * What this site has saved for a setting, or null. With `effective`, the
     * value in force, which is the default where nothing is saved.
     */
    private function stored(string $key, bool $effective = false): ?string
    {
        $application = $this->target();
        $settings = $application instanceof Application ? $application->phpSettings : null;

        $value = $effective
            ? ($settings ?? new ApplicationPhpSettings)->effective()[$key] ?? null
            : $settings?->getAttribute($key);

        return $value === null ? null : trim((string) $value);
    }

    /**
     * The site type's supported PHP range, as a rule, or nothing for a type with no range.
     *
     * The same rule `StoreApplicationRequest` appends, so the range is enforced on a version
     * change too, not only at creation. No fallback version is passed: the field is `sometimes`,
     * and a blank one fails the is-it-installed check above.
     *
     * @return array<int, SupportedPhpVersion>
     */
    private function supportedRangeRules(): array
    {
        $application = $this->target();

        if (! $application instanceof Application) {
            return [];
        }

        $type = app(SiteTypeManager::class)->find((string) $application->site_type);

        if ($type === null || ($range = $type->supportedPhpRangeFor($application)) === null) {
            return [];
        }

        return [new SupportedPhpVersion(
            $range['min'] ?? null,
            $range['max'] ?? null,
            app(SiteTypeText::class)->title($type->name()),
        )];
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
