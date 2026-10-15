<?php

namespace App\Http\Requests\Server\Application;

use App\Enums\SupervisorMode;
use App\Models\Application;
use App\Rules\SupportedNodeVersion;
use App\Services\Applications\SiteTypeManager;
use App\Services\Applications\SiteTypeText;
use App\Services\Server\Runtimes\NodeRuntime;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Which Node version a site moves to (junior re-test #12).
 *
 * The same three questions creating a site asks — installed, a version
 * string, inside the range the site type runs on — plus the ones only a
 * running site has: is it a Node site at all, is a switch already under way,
 * and is it one the panel's own units run (an adopted v7 PM2 process has none
 * to rewrite).
 */
class UpdateNodeVersionRequest extends FormRequest
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
        $rules = [
            'node_version' => [
                'required', 'string', 'max:20', 'regex:/^\d+\.\d+\.\d+$/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! app(NodeRuntime::class)->installed((string) $value)) {
                        $fail(__('errors/node.not_installed', ['version' => $value]));
                    }
                },
            ],
        ];

        $type = app(SiteTypeManager::class)->find((string) $this->application()->site_type);

        if ($type !== null && ($range = $type->supportedNodeRange()) !== null) {
            $rules['node_version'][] = new SupportedNodeVersion(
                $range['min'] ?? null,
                $range['max'] ?? null,
                app(SiteTypeText::class)->title($type->name()),
            );
        }

        return $rules;
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $application = $this->application();

                $refusal = match (true) {
                    $application->serving_profile !== 'node' => 'errors/node.change_not_node',
                    $application->supervisor_mode === SupervisorMode::Pm2 => 'errors/node.change_legacy_pm2',
                    $application->node_version_target !== null && $application->node_version_failed_reason === null => 'errors/node.change_in_progress',
                    default => null,
                };

                if ($refusal !== null) {
                    $validator->errors()->add('node_version', __($refusal, ['version' => $application->node_version_target]));
                }
            },
        ];
    }

    public function nodeVersion(): string
    {
        return (string) $this->validated('node_version');
    }

    private function application(): Application
    {
        /** @var Application */
        return $this->route('application');
    }
}
