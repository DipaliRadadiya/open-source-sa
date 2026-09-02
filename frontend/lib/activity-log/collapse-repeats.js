/**
 * Collapse a run of identical entries into one row carrying a count.
 *
 * The activity log is mostly logins — the last hundred entries on a live panel
 * were ninety-nine `user.logged_in` and one sync — so an ungrouped feed spends
 * every row it has on the same person signing in, and the one event worth
 * seeing is pushed off the bottom. Grouping the repeats buys those rows back
 * without hiding anything: the count says how many there were.
 *
 * Only CONSECUTIVE entries merge, so the feed stays in the order it happened.
 * Two logins either side of a role change stay two rows, because collapsing
 * them would put the role change out of sequence.
 *
 * Same action, same actor, same type. A login by one admin and a login by
 * another are two different facts and must not become "2×".
 */
const sameEvent = (a, b) =>
  a.action === b.action &&
  a.type === b.type &&
  (a.user?.id ?? null) === (b.user?.id ?? null) &&
  Boolean(a.is_system) === Boolean(b.is_system);

const identity = (entry) =>
  [entry.type, entry.action, entry.user?.id ?? "", Boolean(entry.is_system)].join("|");

/**
 * `mergeAcross` lists actions that merge even when they are NOT adjacent.
 *
 * Adjacency is the right rule for anything you might read as a sequence, but
 * it falls apart on logins: two people signing in over an afternoon interleave,
 * so one run of fifty becomes twenty little runs, and a feed showing the first
 * two of those reports "test logged in" and "admin logged in" over a hundred
 * hidden entries. Merged per person instead, the same window reads "test logged
 * in 49 times" and "admin logged in once", which is what happened.
 *
 * Each merged group keeps the position of that person's most recent one, so
 * the card is still ordered by when things last happened.
 */
export function collapseRepeats(entries = [], { max = Infinity, mergeAcross = [] } = {}) {
  const groups = [];
  const merged = new Map();

  for (const entry of entries) {
    if (mergeAcross.includes(entry.action)) {
      const existing = merged.get(identity(entry));
      if (existing) {
        existing.count += 1;
        existing.oldest = entry;
        continue;
      }
      const group = { key: String(entry.id), newest: entry, oldest: entry, count: 1 };
      merged.set(identity(entry), group);
      groups.push(group);
      continue;
    }

    const last = groups[groups.length - 1];
    if (last && sameEvent(last.newest, entry)) {
      last.count += 1;
      // Entries arrive newest first, so each further match is older.
      last.oldest = entry;
      continue;
    }
    groups.push({ key: String(entry.id), newest: entry, oldest: entry, count: 1 });
  }

  return groups.slice(0, max);
}
