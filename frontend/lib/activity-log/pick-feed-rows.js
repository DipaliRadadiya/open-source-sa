/**
 * Choose which collapsed groups the dashboard feed shows. Quiet actions
 * (logins) get a fixed allowance of the most recent runs; the rest of the
 * card goes to everything else. Order is never changed. `quiet` is a
 * parameter so the caller (and tests) decide.
 */
export const QUIET_ACTIONS = ["logged_in"];

export function pickFeedRows(groups = [], { max = 6, quiet = QUIET_ACTIONS, maxQuiet = 2 } = {}) {
  const isQuiet = (group) => quiet.includes(group.newest?.action);

  let quietUsed = 0;
  const kept = [];

  for (const group of groups) {
    if (kept.length >= max) break;
    if (isQuiet(group)) {
      if (quietUsed >= maxQuiet) continue;
      quietUsed += 1;
    }
    kept.push(group);
  }

  // Only quiet actions happened: show them rather than an empty card.
  if (!kept.length && groups.length) return groups.slice(0, max);

  return kept;
}
