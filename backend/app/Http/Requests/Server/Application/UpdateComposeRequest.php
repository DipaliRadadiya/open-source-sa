<?php

namespace App\Http\Requests\Server\Application;

use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ComposeValidator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A hand-edited compose file for a site that already exists.
 *
 * The create form validates the same way. Here the site is already serving, so a refusal must
 * arrive on the field before anything is written; the action rolls back if applying fails anyway.
 */
class UpdateComposeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManage('app_compose') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Required and non-empty, unlike the create form where empty means "generate one".
            // Clearing the file would mean stopping the site, which the disable control is for.
            'compose' => ['required', 'string', 'max:131072'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $application = $this->route('application');

                // Validated against the site's REAL document root, not a temp directory as on the
                // create form: the bind-mount rule checks a source does not escape the
                // application's own tree, or it could mount the neighbours' data.
                $composeValidator = app(ComposeValidator::class);
                $verdict = $composeValidator->validate(
                    (string) $this->input('compose'),
                    app(ApplicationProvisioner::class)->documentRoot($application),
                );

                foreach ($verdict['errors'] as $error) {
                    $validator->errors()->add('compose', $error);
                }

                // A port another site holds; this site's own port is not a clash (otherwise the
                // save would hit the unique index and answer 500).
                $taken = $verdict['resolved'] === null ? null : $composeValidator->portTaken(
                    $verdict['resolved'],
                    $application->container_port ? (int) $application->container_port : null,
                    $application->getKey(),
                );

                if ($taken !== null) {
                    $validator->errors()->add('compose', $taken);
                }

                // Docker's own networks and other sites' private ones, as the Container card's
                // network field also refuses.
                $network = $verdict['resolved'] === null ? null : $composeValidator->unjoinableNetwork(
                    $verdict['resolved'],
                    (int) $application->getKey(),
                );

                if ($network !== null) {
                    $validator->errors()->add('compose', $network);
                }
            },
        ];
    }
}
