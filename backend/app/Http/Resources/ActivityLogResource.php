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
            // Which half of the panel this row is about, so the frontend need not keep the map.
            'scope' => app(ActivityScopes::class)->for($this->type),
            'description' => $this->description(),
            // Old → new, worded for the reader: null when the event records no changes, `[]` when
            // the save changed nothing. Secrets carry no values, only that they changed.
            'changes' => $this->changes(),
            'user' => $this->when($this->relationLoaded('user'), fn () => match (true) {
                $this->user !== null => [
                    'id' => $this->user->id,
                    'username' => $this->user->username,
                    'deleted' => false,
                ],
                // The account is gone; the person still did this. Not whenLoaded(): it answers
                // null for a null relation before the closure is asked.
                $this->user_name !== null => [
                    'id' => null,
                    'username' => $this->user_name,
                    'deleted' => true,
                ],
                default => null,
            }),
            // No person did this (scheduled reboot, automatic disk clean, git webhook deploy).
            // Stated outright, as a null user alone cannot tell "the system did it" from "the row
            // lost its user". Never write an admin's id onto system actions: the audit log would
            // name someone who was not there. A failed sign-in on an unknown name is not the system.
            'is_system' => $this->user_id === null && $this->user_name === null
                && ! ($this->type === 'user' && $this->action === 'login_failed_unknown'),
            // The same classification the filters use.
            'kind' => app(ActivityKinds::class)->of($this->action),
            'is_security' => app(ActivityKinds::class)->isSecurity($this->type, $this->action),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }

    /**
     * The row's properties, in a shape `__()` can substitute.
     *
     * 🔴 `Translator::makeReplacements()` calls `ucfirst()` on every value, so one array property
     * is a `TypeError` that takes down the whole activity log, not just that row
     * (`container_secrets_viewed`, `docker_resources_removed` record lists). Rows already stored
     * hold arrays, so refusing to record them would not fix those.
     *
     * The words live in `activity_values.php`, NOT `activity.php`: that file's keys are the
     * event-type list `/activity-log/filters` builds its dropdowns from.
     *
     * Arrays are joined (a list of names is what the log is asked for; an empty list says so in
     * words). Booleans become words too: PHP casts `false` to `''`, which rendered as a gap.
     *
     * @return array<string, mixed>
     */
    private function replacements(): array
    {
        // Rendered separately ({@see changes()}); an array of arrays would read "…, …".
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

        // Stored as a code so it reads in the viewer's language: why a deleted site's adopted
        // config was left in place. An unknown code stays as it is.
        $reason = $properties['left_reason'] ?? null;

        if (is_string($reason) && Lang::has('activity_values.adopted_left_'.$reason)) {
            $replacements['left_reason'] = __('activity_values.adopted_left_'.$reason);
        }

        // A cron job switched off plain `php` came from the server default, stored as a code.
        if ($this->type === 'cronjob' && ($properties['from'] ?? null) === CronPhpBinary::DEFAULT) {
            $replacements['from'] = __('activity_values.php_default');
        }

        // The settings group is stored as its key (`security`); translate it for the sentence.
        $group = $properties['group'] ?? null;

        if ($this->type === 'setting' && is_string($group) && Lang::has('activity_values.setting_groups.'.$group)) {
            $replacements['group'] = __('activity_values.setting_groups.'.$group);
        }

        // The keep choice a disk clean ran with; a row written before it existed reads "default".
        if ($this->type === 'disk_cleaner') {
            $replacements['kept'] = KeepDays::label($properties['keep_days'] ?? null);
        }

        return $replacements;
    }

    /**
     * The sentence for this row, never a raw key and never a bare `:name`.
     *
     * Laravel answers a missing translation with the key itself, so a missing sentence falls
     * back to words built from the event, and a warning names it. A placeholder the row has no
     * value for (written before the property existed) reads "—" instead of `:name`.
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

        // An action done with the safety copy turned off (staging push/pull, `.env` and
        // file-manager saves and restores) says so.
        if ((((array) ($this->properties ?? []))['safety_copy'] ?? null) === false) {
            $sentence = __('activity_values.without_safety_copy', ['sentence' => $sentence]);
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
     * `properties.changes` in the reader's language: field label, old and new value, and the
     * phrase they make.
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
