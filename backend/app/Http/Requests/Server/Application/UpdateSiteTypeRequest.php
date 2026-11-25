<?php

namespace App\Http\Requests\Server\Application;

use App\Models\Application;
use App\Services\Applications\SiteTypeDetector;
use App\Services\Applications\SiteTypeManager;
use App\Services\Applications\SiteTypeText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Relabel a site: tell the panel what is actually installed in it.
 *
 * This changes what the panel offers, not what is on disk: nothing is installed or converted.
 * The site type decides which screens a site gets (WordPress adds Staging, Clone and Magic
 * Login), so a WordPress installed by hand into a Custom PHP site stays Custom PHP until relabelled.
 *
 * Allowed changes are deliberately asymmetric:
 *
 *   - **Narrowing** to a generic type is always allowed: the site gives up features and claims
 *     nothing.
 *
 *   - **Widening** from a generic type needs evidence on disk. A WordPress type turns on
 *     `app_magic_login`, which writes a loader into the site, so it must not target a directory
 *     without WordPress.
 *
 *   - **A git site cannot be relabelled at all.** Its Deployments, Workers and .env screens exist
 *     because `method() === 'git'` (see AbstractSiteType::features()); relabelling would leave
 *     running workers and a live deploy webhook with no screen to manage them.
 *
 *   - **Marketplace to marketplace is meaningless.**
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
        // Whole catalog, not allowed transitions: an unknown type is malformed, while a disallowed
        // one gets a specific reason (see refuseDisallowedTransition).
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

        // Ordered so the most specific refusal wins (a git site hears about its workers first).
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

        // The escape hatch: no evidence needed to narrow, or a site whose WordPress was deleted
        // would be stuck.
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
     * Detected at apply time, not from the verdict the Detect button stored, which may be stale.
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
                'type' => app(SiteTypeText::class)->title($target),
            ]));
        }
    }
}
