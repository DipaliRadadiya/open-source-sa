<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use App\Services\Applications\SiteTypeDetector;
use App\Services\Applications\SiteTypeManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Relabel a site: tell the panel what is actually installed in it.
 *
 * **This changes what the panel offers, not what is on disk.** Nothing is
 * installed, nothing is downloaded, no installer runs. The site type decides
 * which screens a site gets — WordPress adds Staging, Clone and Magic Login —
 * and this endpoint exists because a user who installs WordPress by hand into
 * a Custom PHP site has, as far as the panel is concerned, a Custom PHP site
 * forever. Said plainly here and in API_REFERENCE.md because the opposite
 * assumption — that changing the type converts the site — is the natural one.
 *
 * Which changes are allowed is not symmetric, and the asymmetry is the design:
 *
 *   - **Narrowing** to a generic type is always allowed. The site gives up
 *     features; it claims nothing. This is the escape hatch for a wrong guess
 *     and for a site that has since been replaced by something else.
 *
 *   - **Widening** from a generic type must be backed by evidence on disk.
 *     Turning a site into a WordPress site turns on `app_magic_login`, which
 *     *writes a loader into the site*. Point that at a directory containing no
 *     WordPress and the panel is writing files an application cannot use.
 *
 *   - **A git site cannot be relabelled at all**, in either direction. Its
 *     Deployments, Workers and .env screens exist because `method() === 'git'`
 *     (see AbstractSiteType::features()), and relabelling does not stop the
 *     things behind them: the supervisord workers keep running and the deploy
 *     webhook keeps accepting pushes, with no screen left to manage either.
 *     Nothing is deleted — it becomes invisible, which is worse than refused.
 *
 *   - **Marketplace to marketplace is meaningless.** A Moodle site is not
 *     "actually" Joomla; whoever is asking has a different problem.
 */
class UpdateSiteTypeRequest extends FormRequest
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
        // Validated against the whole catalog, not against the allowed
        // transitions. An unknown string is a malformed request; a known type
        // that this site may not become is a refusal with a reason the user
        // can act on, and collapsing the two would answer "invalid selection"
        // to a question that deserves a sentence.
        return [
            'site_type' => ['required', 'string', Rule::in(app(SiteTypeManager::class)->names())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->refuseDisallowedTransition($validator);
        });
    }

    private function refuseDisallowedTransition(Validator $validator): void
    {
        /** @var Application $application */
        $application = $this->route('application');

        $current = (string) $application->site_type;
        $target = (string) $this->validated('site_type');

        $generic = (array) config('server.site_type_detection.generic', []);
        $suggestable = (array) config('server.site_type_detection.suggestable', []);

        $refuse = fn (string $reason) => $validator->errors()->add(
            'site_type',
            __('application.site_type_change.'.$reason),
        );

        // Ordered so that the most specific refusal wins. A git site asking to
        // become WordPress should be told about its workers, not told that
        // WordPress needs evidence.
        if ($current === 'git') {
            $refuse('git_cannot_change');

            return;
        }

        if ($target === 'git') {
            $refuse('git_not_a_target');

            return;
        }

        if ($target === $current) {
            $refuse('unchanged');

            return;
        }

        // The escape hatch. No evidence required in this direction: a user
        // telling the panel to stop treating a site as WordPress is not a
        // claim that needs proving, and requiring proof would trap a site
        // whose WordPress has since been deleted.
        if (in_array($target, $generic, true)) {
            return;
        }

        if (! in_array($target, $suggestable, true)) {
            $refuse('not_suggestable');

            return;
        }

        if (! in_array($current, $generic, true)) {
            $refuse('only_from_generic');

            return;
        }

        $this->refuseWithoutEvidence($validator, $application, $target);
    }

    /**
     * The disk has to agree.
     *
     * Detected here, at apply time, rather than trusting the verdict the
     * Detect button stored. Six `test -f` probes are cheaper than reasoning
     * about how stale a cached verdict is, and it closes the window where
     * somebody deletes WordPress between clicking Detect and clicking Apply.
     */
    private function refuseWithoutEvidence(
        Validator $validator,
        Application $application,
        string $target,
    ): void {
        $verdict = app(SiteTypeDetector::class)->detect($application);

        $floor = (int) config('server.site_type_detection.min_confidence', 60);

        if ($verdict->siteType !== $target || $verdict->confidence < $floor) {
            $validator->errors()->add('site_type', __('application.site_type_change.no_evidence', [
                'type' => __("application.types.{$target}.title"),
            ]));
        }
    }
}
