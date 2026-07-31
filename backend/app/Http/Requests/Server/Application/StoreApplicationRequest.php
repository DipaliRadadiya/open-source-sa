<?php

namespace App\Http\Requests\Server\Application;

use App\Rules\AvailablePort;
use App\Rules\StartCommand;
use App\Services\Applications\SiteTypeManager;
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
            // Both versions become path segments and, for PHP, part of an
            // executed binary path. `max:10` alone let a newline through.
            'php_version' => ['nullable', 'string', 'max:10', 'regex:/^\d+\.\d+$/'],
            'node_version' => ['nullable', 'string', 'max:10', 'regex:/^\d+(\.\d+)*$/'],
            // Any port the owner of this server wants, provided nothing is
            // already using it. The auto-allocated range is a default, not a
            // restriction.
            'app_port' => ['nullable', 'integer', 'between:1024,65535', new AvailablePort],
            // Becomes systemd's ExecStart, which is not a shell — see the rule.
            'start_command' => ['nullable', 'string', 'max:500', new StartCommand],
            // A relative path under the site, and nothing else. This reaches
            // `mkdir -p`, `chown -R` and `tee` as root, so a `..` segment would
            // walk out of the site — `web_root=../../../../etc` handed /etc to
            // the site's own user.
            'web_root' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\-\/]+$/', 'not_regex:/(^|\/)\.\.(\/|$)/'],
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

                // The same check the card grid renders from, so a card the user
                // could click can never be refused here for a reason the grid
                // did not show.
                if (($blocked = $manager->unavailable($type)) !== null) {
                    $validator->errors()->add('site_type', $blocked['reason']);
                }
            },
        ];
    }
}
