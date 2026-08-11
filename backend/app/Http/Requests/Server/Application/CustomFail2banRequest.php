<?php

namespace App\Http\Requests\Server\Application;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The two raw INI blobs the panel writes to /etc/fail2ban/{jail,filter}.d/.
 *
 * Both are required: a save with one half empty would leave fail2ban loading
 * a half-configured jail and rejecting reloads for reasons unrelated to what
 * the user actually changed. The 65535-byte cap matches what MySQL's TEXT
 * column accepts and is well above the size of any sane INI file.
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
}
