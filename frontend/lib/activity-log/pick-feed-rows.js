/**
 * Choose which collapsed groups the dashboard feed shows.
 *
 * Collapsing repeats is not enough on its own. A panel that four people use
 * produces runs of logins separated by other logins, so the feed fills with
 * `Logged in 3×`, `Logged in 5×`, `Logged in 2×` and the role change further
 * down never gets a row. Everything else on this card is something an
 * administrator might act on; logins are the background noise it happens
 * against.
 *
 * So the noisy action gets a fixed allowance — the two most recent runs — and
 * the rest of the card goes to whatever else happened. Order is never changed:
 * the surviving rows stay in the sequence they occurred, so the card cannot
 * imply that something happened before something else.
 *
 * `logged_in` is the only quiet action by default. It is passed in rather than
 * hardcoded here so the caller decides, and so a test can prove the rule
 * without depending on that one name.
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

  // Nothing but logins happened: showing an empty card would be a lie about a
  // panel that is simply quiet, so the allowance is ignored rather than
  // enforced against itself.
  if (!kept.length && groups.length) return groups.slice(0, max);

  return kept;
}
