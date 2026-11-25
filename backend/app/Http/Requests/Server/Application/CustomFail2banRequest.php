<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use App\Services\Server\Applications\ApplicationFail2banManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The two raw INI blobs the panel writes to /etc/fail2ban/{jail,filter}.d/.
 *
 * Both are required: one empty half would leave fail2ban with a half-configured jail. The
 * 65535-byte cap matches MySQL's TEXT column and is far above any sane INI file.
 */
class CustomFail2banRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_fail2ban') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'jail_config_content' => ['required', 'string', 'max:65535'],
            'filter_config_content' => ['required', 'string', 'max:65535'],
            // Keep the files this replaces until the reload has held; on unless turned off.
            'safety_copy' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The jail may only be this site's own.
     *
     * The content is the user's and a jail file can name any jail: `[sshd]` replaces the server's
     * SSH jail, `[DEFAULT]` changes every jail, `filter = sshd` borrows fail2ban's own filter.
     * `fail2ban-client -t` accepts all three, so every section must be `{name}` or the site's jail
     * name, and every `filter =` must be `{filter}` or the same.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Application $application */
                $application = $this->route('application');
                $own = app(ApplicationFail2banManager::class)->jailName($application);
                $jail = (string) $this->input('jail_config_content');

                preg_match_all('/^\s*\[([^\]\r\n]*)\]/m', $jail, $sections);

                foreach ($sections[1] as $section) {
                    if (! in_array(trim($section), ['{name}', $own], true)) {
                        $validator->errors()->add('jail_config_content', __('fail2ban.validation.foreign_jail', [
                            'section' => trim($section), 'name' => $own,
                        ]));

                        return;
                    }
                }

                // Allowlist checked first: a site's jail tunes when to ban, never what a ban does.
                $disallowed = app(ApplicationFail2banManager::class)->disallowedJailKeys($jail);

                if ($disallowed !== []) {
                    $validator->errors()->add('jail_config_content', __('fail2ban.validation.disallowed_setting', [
                        'setting' => $disallowed[0],
                        'allowed' => implode(', ', ApplicationFail2banManager::ALLOWED_JAIL_KEYS),
                    ]));

                    return;
                }

                preg_match_all('/^\s*filter\s*=\s*([^\s\[]*)/mi', $jail, $filters);

                foreach ($filters[1] as $filter) {
                    if (! in_array($filter, ['{filter}', $own], true)) {
                        $validator->errors()->add('jail_config_content', __('fail2ban.validation.foreign_filter', [
                            'filter' => $filter, 'name' => $own,
                        ]));

                        return;
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'jail_config_content.required' => __('fail2ban.validation.jail_content_required'),
            'jail_config_content.string' => __('fail2ban.validation.jail_content_string'),
            'jail_config_content.max' => __('fail2ban.validation.jail_content_max'),
            'filter_config_content.required' => __('fail2ban.validation.filter_content_required'),
            'filter_config_content.string' => __('fail2ban.validation.filter_content_string'),
            'filter_config_content.max' => __('fail2ban.validation.filter_content_max'),
        ];
    }

    public function safetyCopy(): bool
    {
        return $this->boolean('safety_copy', true);
    }
}
