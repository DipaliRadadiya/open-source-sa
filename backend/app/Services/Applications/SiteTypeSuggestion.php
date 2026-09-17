<?php

namespace App\Services\Applications;

use App\Models\Application;

/**
 * Whether the panel should offer to relabel a site, and how to say it.
 *
 * One class rather than a condition in the controller and a second copy in the
 * resource. The detect endpoint answers "here is what I found" and the
 * application payload answers "there is a pending suggestion" — the same
 * judgement, asked twice, and the two drifting apart is how a panel ends up
 * offering a change on one screen and refusing it on the next.
 *
 * It deliberately says no more often than the detector says something. A
 * verdict is a fact about the disk; a suggestion is a recommendation to a
 * user, and it is only worth making when acting on it would be allowed.
 */
class SiteTypeSuggestion
{
    /**
     * The payload for the Detect button and for the application resource.
     *
     * `suggested` is null whenever there is nothing to offer, so the frontend
     * has one field to test and no rules of its own to reimplement. The
     * evidence travels with it: a suggestion the user cannot check is one they
     * have to take on faith.
     *
     * @return array<string, mixed>
     */
    public function describe(Application $application, ?SiteTypeVerdict $verdict = null): array
    {
        $stored = (array) ($application->settings['type_detection'] ?? []);

        $detected = $verdict?->siteType ?? ($stored['detected'] ?? null);
        $confidence = $verdict?->confidence ?? ($stored['confidence'] ?? null);
        $matched = $verdict?->matched ?? ($stored['matched'] ?? null);

        return [
            'detected' => $detected,
            'detected_title' => $detected === null ? null : __("application.types.{$detected}.title"),
            'confidence' => $confidence === null ? null : (int) $confidence,
            // The file the verdict rests on, so the note can say *why*.
            'matched' => $matched,
            'checked_at' => $stored['checked_at'] ?? null,
            'suggested' => $this->suggested($application, $detected, $confidence),
        ];
    }

    /**
     * The type to offer, or null.
     *
     * Every condition here is a refusal the apply endpoint would also make, so
     * a suggestion the user accepts cannot come back as a 422.
     */
    private function suggested(Application $application, ?string $detected, int|string|null $confidence): ?string
    {
        if ($detected === null || $confidence === null) {
            return null;
        }

        $current = (string) $application->site_type;

        // A git site is never offered a relabel. Its Deployments, Workers and
        // .env screens come from `method() === 'git'`, and taking them away
        // does not stop the workers running or the webhook accepting pushes —
        // it only removes the screens that manage them.
        if ($current === 'git') {
            return null;
        }

        if ($detected === $current) {
            return null;
        }

        if ((int) $confidence < (int) config('server.site_type_detection.min_confidence', 60)) {
            return null;
        }

        // Widening only, and only from a type that promises nothing. The
        // narrowing escape hatch is always *available* through the API; it is
        // just not something to nag a working site about.
        if (! in_array($current, (array) config('server.site_type_detection.generic', []), true)) {
            return null;
        }

        if (! in_array($detected, (array) config('server.site_type_detection.suggestable', []), true)) {
            return null;
        }

        return $detected;
    }
}
