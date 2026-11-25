<?php

namespace App\Support;

use App\Http\Resources\ActivityLogResource;

/**
 * What a save actually changed, for the activity log: "Changed the SSH port from 22 to
 * 2222" rather than "Updated security settings".
 *
 * Built from the values before and after the save, never from the request: a field sent
 * with its existing value is not a change, and a normalised value ("UTC " → "UTC") is
 * recorded as it really is.
 *
 * Stored on the row as `properties.changes`, a list of
 * `['field' => 'port', 'from' => 22, 'to' => 2222]`. A secret is recorded only as having
 * changed (`['field' => 'password', 'secret' => true]`), never its value. The activity
 * screen words it in the reader's language ({@see ActivityLogResource}).
 */
final class ActivityChanges
{
    /**
     * The fields among `$fields` whose value differs, in that order.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<string>  $fields
     * @return list<array{field: string, from: mixed, to: mixed}>
     */
    public static function between(array $before, array $after, array $fields): array
    {
        $changes = [];

        foreach ($fields as $field) {
            $from = self::normal($before[$field] ?? null);
            $to = self::normal($after[$field] ?? null);

            if ($from !== $to) {
                $changes[] = ['field' => $field, 'from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /**
     * A secret that was set, changed or removed — never its value.
     *
     * @return array{field: string, secret: true, removed?: true}
     */
    public static function secret(string $field, bool $removed = false): array
    {
        return ['field' => $field, 'secret' => true] + ($removed ? ['removed' => true] : []);
    }

    /**
     * Scalars keep their type, so "8" and 8 are one value; arrays become JSON, so a list
     * compares by content; empty strings become null, so "" → null is not a change.
     */
    private static function normal(mixed $value): mixed
    {
        return match (true) {
            $value === '' => null,
            is_int($value), is_float($value), is_bool($value), $value === null => $value,
            is_numeric($value) && (string) (int) $value === (string) $value => (int) $value,
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
    }
}
