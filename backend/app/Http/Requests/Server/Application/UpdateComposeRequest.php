<?php

namespace App\Http\Requests\Server\Application;

use App\Services\Server\Applications\ApplicationProvisioner;
use App\Services\Server\Applications\ComposeValidator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A hand-edited compose file for a site that already exists.
 *
 * The create form validates the same way and for the same reason, stated in its
 * own comment: a refused file accepted by the form becomes a 502 and an
 * application the user has to delete. Here the stakes are higher — the site is
 * already serving — so the refusal has to arrive on the field before anything is
 * written, and the action rolls back if applying it fails anyway.
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
            // Required and non-empty, unlike on the create form where an empty
            // value means "generate one from the fields". Clearing the file here
            // would be asking to stop the site, which is what the disable control
            // is for.
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

                // Validated against the site's REAL document root, not a temp
                // directory as the create form must use. The bind-mount rule asks
                // whether a source escapes the application's own tree, and on an
                // existing site that tree is the thing to check against — a file
                // that passes against /tmp and mounts the neighbours' data is the
                // failure this rule exists to stop.
                $verdict = app(ComposeValidator::class)->validate(
                    (string) $this->input('compose'),
                    app(ApplicationProvisioner::class)->documentRoot($application),
                );

                foreach ($verdict['errors'] as $error) {
                    $validator->errors()->add('compose', $error);
                }
            },
        ];
    }
}
