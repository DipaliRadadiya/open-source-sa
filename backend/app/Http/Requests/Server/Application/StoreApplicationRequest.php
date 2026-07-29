<?php

namespace App\Http\Requests\Server\Application;

use App\Services\Applications\SiteTypeManager;
use App\Services\Server\Capabilities\ServerCapabilities;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation is driven by the chosen site type's own field schema, so the form
 * and the rules can never drift apart: WordPress demands an admin email, git
 * demands a repository, and neither can be satisfied by sending the other's
 * fields.
 */
class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('application') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $manager = app(SiteTypeManager::class);
        $type = $manager->find((string) $this->input('site_type'));

        $rules = [
            'site_type' => ['required', Rule::in($manager->names())],
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/'],
            'system_user_id' => ['required', 'integer', 'exists:system_users,id'],
            'php_version' => ['nullable', 'string', 'max:10'],
            'node_version' => ['nullable', 'string', 'max:10'],
            'app_port' => ['nullable', 'integer', 'between:1024,65535'],
            'web_root' => ['nullable', 'string', 'max:255'],
        ];

        // An unknown site_type fails on the rule above; skip type rules so we
        // don't resolve fields on null.
        return $type === null ? $rules : array_merge($rules, $type->rules());
    }

    /**
     * The server has to be able to serve the chosen type — otherwise the
     * record would describe something that can never be provisioned.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $manager = app(SiteTypeManager::class);
                $type = $manager->find((string) $this->input('site_type'));

                if ($type === null) {
                    return;
                }

                $runtime = $manager->requiredRuntime($type->servingProfile());

                if ($runtime !== null && ! app(ServerCapabilities::class)->supports($runtime)) {
                    $validator->errors()->add('site_type', __("application.unavailable.{$runtime}"));
                }
            },
        ];
    }
}
