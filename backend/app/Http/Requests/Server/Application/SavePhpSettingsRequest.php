<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\InstallStatus;
use App\Models\Application;
use App\Models\ApplicationPhpSettings;
use App\Rules\SupportedPhpVersion;
use App\Services\Applications\SiteTypeManager;
use App\Services\Runtime\InstallTracker;
use App\Services\Server\Php\AdditionalDirectives;
use App\Services\Server\Php\MemoryBudget;
use App\Services\Server\Php\PhpVersionManager;
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
            // Checked against what is actually on the server, not just the
            // shape of the string. Unvalidated, `8.9` was accepted, saved, and
            // only surfaced as a pool write into an /etc/php directory that
            // does not exist — a failure the user could not read as "that
            // version is not installed". Resolved lazily so a save that does
            // not touch the version costs nothing.
            'php_version' => array_merge([
                'sometimes', 'string', 'max:8',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! app(PhpVersionManager::class)->exists((string) $value)) {
                        $fail(__('php_settings.errors.version_not_installed', ['version' => $value]));

                        return;
                    }

                    // Its directory exists as soon as apt starts, so "exists"
                    // passed mid-install — and the site's pool, written into
                    // it then, made apt's own start of php-fpm fail on a
                    // socket the old version still held: the install was
                    // marked failed and the switch refused (measured on a
                    // real server, 09:19:40). Wait for the install instead.
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
            'allow_url_fopen' => ['sometimes', 'nullable', 'boolean'],
            'php_timezone' => ['sometimes', 'nullable', 'timezone'],
            'auto_prepend_file' => ['sometimes', 'nullable', 'string', 'max:255', 'not_regex:/\.\./', $this->prependInsideSiteRule()],

            // The escape hatch, and the only free-text field. Newlines are
            // allowed because it is ini; a `[section]` header is not, because
            // that would silently start a second pool inside this file.
            'additional_directives' => ['sometimes', 'nullable', 'string', 'max:4000', 'not_regex:/^\s*\[/m',
                // PHP settings the panel does not set itself, one per line —
                // see AdditionalDirectives.
                function (string $attribute, mixed $value, Closure $fail): void {
                    $refusal = app(AdditionalDirectives::class)->refusal((string) $value);

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

    /**
     * Bug #61: `-1` and `99G` passed, being the right shape.
     *
     * `-1` is unlimited, which on a pool lets one site's script take the
     * whole server's memory and every other site with it. Operator's call
     * (2026-10-03): refused for everyone. A site that already has it keeps
     * it until the value is changed: refusing an unchanged field would
     * make every other setting on the screen unsaveable.
     *
     * Above the server's RAM is not a limit at all. Unknown RAM (no
     * /proc/meminfo) checks nothing rather than refusing everything.
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

            $ram = app(MemoryBudget::class)->totalMemoryBytes();

            if ($ram > 0 && ApplicationPhpSettings::toBytes($value) > $ram) {
                $fail(__('php_settings.errors.memory_over_ram', ['ram' => Number::fileSize($ram, maxPrecision: 1)]));
            }
        };
    }

    /**
     * An upload travels inside the POST body, so a post_max_size below
     * upload_max_filesize makes every upload between the two fail, and PHP
     * says so only by handing the script an empty `$_POST` (bug #61).
     *
     * Compared as they will be after the save: a field not sent keeps its
     * stored value, so raising only the upload size is caught too. `0` and
     * `-1` mean no limit and are left alone.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
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
     * Bug #61: only `..` was refused, so any absolute path passed, another
     * site's files included, run at the top of every request of this one.
     * Now it must be an absolute path inside this site's own folder.
     */
    private function prependInsideSiteRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $value = trim((string) $value);

            if ($value === '' || $value === $this->stored('auto_prepend_file')) {
                return;
            }

            $application = $this->route('application');
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
        $application = $this->route('application');
        $settings = $application instanceof Application ? $application->phpSettings : null;

        $value = $effective
            ? ($settings ?? new ApplicationPhpSettings)->effective()[$key] ?? null
            : $settings?->getAttribute($key);

        return $value === null ? null : trim((string) $value);
    }

    /**
     * The site type's supported PHP range, as a rule — or nothing, for a type
     * that declares no range.
     *
     * The same rule object `StoreApplicationRequest` appends, deliberately:
     * until this existed the range was enforced when a site was *created* and
     * never again, so a PrestaShop site correctly created on 8.0 could be
     * moved to 8.4 from the PHP screen and simply break. One field, two paths,
     * one of them validated — the same shape as the 2026-09-03 bug where the
     * settings screen refused an uninstalled version and creation did not.
     *
     * No fallback version is passed: unlike creation there is no empty case to
     * resolve. The field is `sometimes`, so not sending it changes nothing,
     * and sending a blank one fails the is-it-installed check above.
     *
     * @return array<int, SupportedPhpVersion>
     */
    private function supportedRangeRules(): array
    {
        $application = $this->route('application');

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
            __("application.types.{$type->name()}.title"),
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
