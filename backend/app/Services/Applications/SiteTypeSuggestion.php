<?php

namespace App\Services\Applications;

use App\Models\Application;

/**
 * Whether the panel should offer to relabel a site, and how to say it.
 *
 * One class because the detect endpoint and the application payload make the same judgement;
 * two copies could offer a change on one screen and refuse it on the next.
 *
 * It says no more often than the detector speaks: a verdict is a fact about the disk, a
 * suggestion is only worth making when acting on it would be allowed.
 */
class SiteTypeSuggestion
{
    /**
     * The payload for the Detect button and for the application resource.
     *
     * `suggested` is null whenever there is nothing to offer, so the frontend has one field to
     * test. The evidence travels with it so the user can check the suggestion.
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
            'detected_title' => $detected === null ? null : app(SiteTypeText::class)->title($detected),
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
     * Every condition here is a refusal the apply endpoint also makes, so an accepted suggestion
     * cannot come back as a 422.
     */
    private function suggested(Application $application, ?string $detected, int|string|null $confidence): ?string
    {
        if ($detected === null || $confidence === null) {
            return null;
        }

        $current = (string) $application->site_type;

        // A git site is never offered a relabel: its Deployments, Workers and .env screens come
        // from `method() === 'git'`, and losing them leaves the workers and webhook running.
        if ($current === 'git') {
            return null;
        }

        if ($detected === $current) {
            return null;
        }

        if ((int) $confidence < (int) config('server.site_type_detection.min_confidence', 60)) {
            return null;
        }

        // Widening only, and only from a type that promises nothing. Narrowing stays available
        // through the API; it is just not suggested.
        if (! in_array($current, (array) config('server.site_type_detection.generic', []), true)) {
            return null;
        }

        if (! in_array($detected, (array) config('server.site_type_detection.suggestable', []), true)) {
            return null;
        }

        return $detected;
    }
}
