<?php

namespace App\Http\Resources;

use App\Services\ActivityKinds;
use App\Services\ActivityScopes;
use App\Services\Server\Cron\CronPhpBinary;
use App\Services\Server\DiskCleaner\KeepDays;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            'description' => $this->description(),
            // What the save changed, old → new, worded for the reader (null for
            // an event that records no changes, `[]` for a save that changed
            // nothing). Secrets carry no values, only that they changed.
            'changes' => $this->changes(),
            'user' => $this->when($this->relationLoaded('user'), fn () => match (true) {
                $this->user !== null => [
                    'id' => $this->user->id,
                    'username' => $this->user->username,
                    'deleted' => false,
                ],
                // The account is gone; the person still did this (FS-C22).
                // Not whenLoaded(): it answers null for a null relation
                // before the closure is ever asked.
                $this->user_name !== null => [
                    'id' => null,
                    'username' => $this->user_name,
                    'deleted' => true,
                ],
                default => null,
            }),
            // No person did this — a scheduled reboot, an automatic disk clean,
            // a deploy from a git webhook. Stated outright rather than left for
            // the frontend to infer from a null user, because "the system did
            // it" and "this row is missing its user" would otherwise look
            // identical, and only one of them is fine.
            //
            // Deliberately not solved by writing an admin's id onto system
            // actions: that would make the audit log name someone who was not
            // there, and put machine activity in their personal history.
            //
            // A sign-in attempt on a name that matches no account has no
            // person to name either, but the system did not do it.
            'is_system' => $this->user_id === null && $this->user_name === null
                && ! ($this->type === 'user' && $this->action === 'login_failed_unknown'),
            // FS-C15 / OLD-18: the same classification the filters use.
            'kind' => app(ActivityKinds::class)->of($this->action),
            'is_security' => app(ActivityKinds::class)->isSecurity($this->type, $this->action),
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
        // Rendered separately ({@see changes()}), and an array of arrays would
        // only reach a sentence as "…, …".
        $properties = array_diff_key((array) ($this->properties ?? []), ['changes' => true]);

        $replacements = array_map(function (mixed $value): mixed {
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

        // A reason stored as a code, so it reads in the viewer's language
        // rather than the deleter's: why a deleted site's adopted config was
        // left in place (FS-C22 #14). An unknown code stays as it is.
        $reason = $properties['left_reason'] ?? null;

        if (is_string($reason) && Lang::has('activity_values.adopted_left_'.$reason)) {
            $replacements['left_reason'] = __('activity_values.adopted_left_'.$reason);
        }

        // The keep choice a disk clean ran with, in the viewer's language.
        // Set for every disk-cleaner row, so one written before the choice
        // existed reads "default" rather than a bare `:kept`.
        // A cron job switched off plain `php` came from the server default,
        // stored as a code so it reads in the viewer's language.
        if ($this->type === 'cronjob' && ($properties['from'] ?? null) === CronPhpBinary::DEFAULT) {
            $replacements['from'] = __('activity_values.php_default');
        }

        // The settings group is stored as its key (`security`), so the
        // sentence read "Einstellungen security aktualisiert" in German.
        $group = $properties['group'] ?? null;

        if ($this->type === 'setting' && is_string($group) && Lang::has('activity_values.setting_groups.'.$group)) {
            $replacements['group'] = __('activity_values.setting_groups.'.$group);
        }

        if ($this->type === 'disk_cleaner') {
            $replacements['kept'] = KeepDays::label($properties['keep_days'] ?? null);
        }

        return $replacements;
    }

    /**
     * The sentence for this row, never a raw key and never a bare `:name`.
     *
     * Laravel answers a missing translation with the key itself, so an event
     * whose sentence was renamed or never written read `activity.x.y` on the
     * screen — a key nobody but a developer can read (support list,
     * 2026-10-28). It now reads as words built from the event, and a warning
     * says which sentence is missing. A placeholder the row has no value for
     * (written before the property existed) reads "—" instead of `:name`.
     */
    private function description(): string
    {
        $key = 'activity.'.$this->type.'.'.$this->action;
        $replacements = $this->replacements();

        if (! Lang::has($key)) {
            Log::warning('activity entry has no sentence', ['key' => $key, 'id' => $this->id]);

            $sentence = Str::ucfirst(str_replace('_', ' ', (string) $this->type)).': '.str_replace('_', ' ', (string) $this->action);
        } else {
            preg_match_all('/(?<![\w:]):([a-zA-Z_]+)/', (string) __($key), $found);

            foreach ($found[1] as $placeholder) {
                $replacements[Str::lower($placeholder)] ??= '—';
            }

            $sentence = __($key, $replacements);
        }

        $changes = $this->changes();

        return match (true) {
            $changes === null => $sentence,
            $changes === [] => __('activity_values.unchanged', ['sentence' => $sentence]),
            default => __('activity_values.with_changes', [
                'sentence' => $sentence,
                'changes' => implode(', ', array_column($changes, 'text')),
            ]),
        };
    }

    /**
     * `properties.changes` in the reader's language: each field's label, its
     * old and new value as words, and the phrase both make.
     *
     * @return list<array{field: string, label: string, from: string|null, to: string|null, secret: bool, text: string}>|null
     */
    private function changes(): ?array
    {
        $changes = ((array) ($this->properties ?? []))['changes'] ?? null;

        if (! is_array($changes)) {
            return null;
        }

        return array_values(array_map(function (mixed $change): array {
            $change = (array) $change;
            $field = (string) ($change['field'] ?? '');
            $label = $this->fieldLabel($field);

            if ($change['secret'] ?? false) {
                return [
                    'field' => $field, 'label' => $label, 'from' => null, 'to' => null, 'secret' => true,
                    'text' => __(($change['removed'] ?? false) ? 'activity_values.change_secret_removed' : 'activity_values.change_secret', ['field' => $label]),
                ];
            }

            $from = $this->valueText($change['from'] ?? null);
            $to = $this->valueText($change['to'] ?? null);

            return [
                'field' => $field, 'label' => $label, 'from' => $from, 'to' => $to, 'secret' => false,
                'text' => __('activity_values.change', ['field' => $label, 'from' => $from, 'to' => $to]),
            ];
        }, $changes));
    }

    private function fieldLabel(string $field): string
    {
        foreach (["activity_fields.{$this->type}.{$field}", "activity_fields.common.{$field}"] as $key) {
            if (Lang::has($key)) {
                return __($key);
            }
        }

        return str_replace('_', ' ', $field);
    }

    private function valueText(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => __('activity_values.none'),
            $value === true => __('activity_values.on'),
            $value === false => __('activity_values.off'),
            default => (string) $value,
        };
    }
}
