<?php

namespace App\Support;

use App\Enums\BackupStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where each site (or standalone database) stands on backups, as ONE SQL expression, so the
 * filter, sort, counts and the row's `state` cannot disagree.
 *
 *   unprotected  no backup settings
 *   paused       settings exist, but switched off or `manual`
 *   failing      on a schedule, and the latest backup failed
 *   protected    on a schedule, and the latest backup did not fail
 *                (none yet, running, verified)
 *
 * "Latest backup" is the newest run of the owner's own target, never a safety copy taken before
 * a restore: that failing says nothing about the schedule.
 *
 * Computed in the database because the list is paged (filtering loaded rows loses later pages).
 * Built only from constants and the owner column given in code, never from the request; values
 * go in as bindings. Portable across SQLite, MySQL/MariaDB and PostgreSQL.
 */
class BackupCoverage
{
    public const UNPROTECTED = 'unprotected';

    public const PAUSED = 'paused';

    public const FAILING = 'failing';

    public const PROTECTED = 'protected';

    /** The order `sort=state` lists them in: what needs attention first. */
    public const STATES = [self::FAILING, self::PROTECTED, self::PAUSED, self::UNPROTECTED];

    /**
     * The state of each row of $query's model, keyed on the backup target's
     * $ownerColumn (`application_id` or `database_id`).
     *
     * @return array{0: string, 1: list<mixed>} SQL and its bindings
     */
    public static function expression(Builder $query, string $ownerColumn): array
    {
        $owner = $query->getModel()->getQualifiedKeyName();
        $grammar = $query->getGrammar();
        $owner = $grammar->wrap($owner);
        $column = $grammar->wrap('bt.'.$ownerColumn);

        $sql = 'case'
            .' when not exists (select 1 from backup_targets bt where '.$column.' = '.$owner.') then ?'
            .' when exists (select 1 from backup_targets bt where '.$column.' = '.$owner.' and (not bt.enabled or bt.frequency = ?)) then ?'
            .' when (select b.status from backups b inner join backup_targets bt on b.backup_target_id = bt.id'
            .' where '.$column.' = '.$owner.' and not b.is_safety'
            .' order by b.id desc limit 1) = ? then ?'
            .' else ? end';

        return [$sql, [
            self::UNPROTECTED,
            'manual', self::PAUSED,
            BackupStatus::Failed->value, self::FAILING,
            self::PROTECTED,
        ]];
    }

    /** Select every column plus the state, as `backup_state`. */
    public static function select(Builder $query, string $ownerColumn): Builder
    {
        [$sql, $bindings] = self::expression($query, $ownerColumn);

        return $query
            ->select($query->getModel()->getTable().'.*')
            ->selectRaw('('.$sql.') as backup_state', $bindings);
    }

    /**
     * Keep the rows in one of $states.
     *
     * @param  list<string>  $states
     */
    public static function only(Builder $query, string $ownerColumn, array $states): Builder
    {
        $states = array_values(array_intersect(self::STATES, $states));

        if ($states === []) {
            return $query->whereRaw('1 = 0');
        }

        [$sql, $bindings] = self::expression($query, $ownerColumn);

        return $query->whereRaw(
            '('.$sql.') in ('.implode(', ', array_fill(0, count($states), '?')).')',
            [...$bindings, ...$states],
        );
    }

    /** Failing first, then protected, paused, unprotected; then by name. */
    public static function sort(Builder $query, string $ownerColumn, bool $descending = false): Builder
    {
        [$sql, $bindings] = self::expression($query, $ownerColumn);
        $ranks = '';
        $rankBindings = [];

        foreach (self::STATES as $rank => $state) {
            $ranks .= ' when ? then '.$rank;
            $rankBindings[] = $state;
        }

        $query->orderByRaw(
            'case ('.$sql.')'.$ranks.' end '.($descending ? 'desc' : 'asc'),
            [...$bindings, ...$rankBindings],
        );

        return ListSort::caseInsensitive($query, 'name');
    }

    /**
     * How many rows of $query are in each state, every state present.
     *
     * @return array<string, int>
     */
    public static function counts(Builder $query, string $ownerColumn): array
    {
        [$sql, $bindings] = self::expression($query, $ownerColumn);

        $counts = array_fill_keys(self::STATES, 0);

        $query->toBase()
            ->selectRaw('('.$sql.') as backup_state, count(*) as aggregate', $bindings)
            ->groupBy('backup_state')
            ->get()
            ->each(function ($row) use (&$counts): void {
                $counts[$row->backup_state] = (int) $row->aggregate;
            });

        return $counts;
    }
}
