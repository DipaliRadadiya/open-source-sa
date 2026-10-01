<?php

namespace App\Http\Resources;

use App\Services\ActivityScopes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'action' => $this->action,
            // Which half of the panel this row is about, so the frontend
            // can badge or group without keeping its own copy of the map.
            'scope' => app(ActivityScopes::class)->for($this->type),
            'description' => __('activity.'.$this->type.'.'.$this->action, $this->replacements()),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'username' => $this->user->username,
            ] : null),
            // No person did this — a scheduled reboot, an automatic disk clean,
            // a deploy from a git webhook. Stated outright rather than left for
            // the frontend to infer from a null user, because "the system did
            // it" and "this row is missing its user" would otherwise look
            // identical, and only one of them is fine.
            //
            // Deliberately not solved by writing an admin's id onto system
            // actions: that would make the audit log name someone who was not
            // there, and put machine activity in their personal history.
            'is_system' => $this->user_id === null,
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }

    /**
     * The row's properties, in a shape `__()` can actually substitute.
     *
     * 🔴 **This endpoint returned a 500 for the whole screen without it.**
     * `Translator::makeReplacements()` calls `ucfirst()` on every value, so one
     * property holding an array is a `TypeError` deep in the framework —
     * `mb_substr(): Argument #1 ($string) must be of type string, array given` —
     * and it takes down the entire activity log, not the one row. Three events do
     * it: `container_secrets_viewed` records the credential KEYS it handed over,
     * and `docker_resources_removed` records what it `removed` and what it `kept`.
     * Every row of all three was already in the database, so refusing to record
     * arrays in future would not have fixed a single existing one.
     *
     * The words live in `activity_values.php`, NOT in `activity.php`: the keys of
     * that file are the event-type list — `/activity-log/filters` builds its
     * dropdowns from them — so a non-event key there becomes a filter option for
     * activity that does not exist. Which is exactly what happened on the first
     * attempt, and a test caught it.
     *
     * Joined rather than counted or dropped. A list of names is the useful half of
     * those events — "removed sv-app-7_data, sv-app-7_db" is what somebody asks the
     * log for — and `count()` would answer "3" to the question "which ones". An
     * empty list says so in words, because `:removed` rendering as nothing reads
     * like a broken sentence rather than a true one.
     *
     * Booleans go to words for the same reason: PHP casts `false` to `''`, so
     * `rolled_back` on a save that did not roll back rendered as a gap.
     *
     * @return array<string, mixed>
     */
    private function replacements(): array
    {
        $properties = (array) ($this->properties ?? []);

        return array_map(function (mixed $value): mixed {
            if (is_array($value)) {
                return $value === []
                    ? __('activity_values.none')
                    : implode(', ', array_map(
                        fn (mixed $item): string => is_scalar($item) ? (string) $item : '…',
                        array_values($value),
                    ));
            }

            if (is_bool($value)) {
                return __($value ? 'activity_values.yes' : 'activity_values.no');
            }

            return $value;
        }, $properties);
    }
}
