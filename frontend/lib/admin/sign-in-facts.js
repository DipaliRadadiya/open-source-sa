// Sign-in facts for the admin dashboard, kept out of the components so each state can be
// tested without a server that is actually in it.

const DAY_MS = 24 * 60 * 60 * 1000;

// Sign-ins have a card of their own, so the activity feed shows changes instead.
export const SIGN_IN_ACTIONS = ["logged_in", "login_failed", "login_failed_unknown"];

// From this many failed attempts in 24 hours the dashboard's attention list warns.
export const FAILED_SIGN_IN_ALERT = 10;

/** The API's `d-m-Y H:i:s`, in UTC (the backend's timezone). */
export function parseActivityTime(value) {
  const m = typeof value === "string" ? value.match(/^(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2}):(\d{2})$/) : null;
  if (!m) return null;
  const [, d, mo, y, h, mi, s] = m.map(Number);
  return Date.UTC(y, mo - 1, d, h, mi, s);
}

// The address is only inside the translated sentence ("Failed sign-in from 203.0.113.9");
// the address itself reads the same in every language.
const IPV4 = /\b(?:\d{1,3}\.){3}\d{1,3}\b/;
const IPV6 = /\b[0-9a-f]{0,4}(?::[0-9a-f]{0,4}){2,7}\b/i;
export function addressIn(description) {
  if (typeof description !== "string") return null;
  return description.match(IPV4)?.[0] ?? description.match(IPV6)?.[0] ?? null;
}

function mostCommon(values) {
  const counts = new Map();
  for (const value of values) if (value) counts.set(value, (counts.get(value) ?? 0) + 1);
  let best = null;
  for (const [value, count] of counts) if (!best || count > best.count) best = { value, count };
  return best;
}

/**
 * Failed attempts over the last 7 days (and the last 24 hours) from the newest rows of both
 * failure actions, with the address most of them came from.
 * `capped`: the read hit its page size inside the window, so the count is a floor.
 */
export function failedSignIns(entries = [], { now = Date.now(), pageSize = 100, reads = [] } = {}) {
  const weekAgo = now - 7 * DAY_MS;
  const dayAgo = now - DAY_MS;
  const rows = entries
    .map((entry) => ({ entry, at: parseActivityTime(entry.created_at) }))
    .filter(({ at }) => at !== null && at >= weekAgo)
    .sort((a, b) => b.at - a.at);
  const capped = reads.some((read) => read.length >= pageSize && read.every((e) => (parseActivityTime(e.created_at) ?? 0) >= weekAgo));

  const address = mostCommon(rows.map(({ entry }) => addressIn(entry.description)));

  return {
    week: rows.length,
    day: rows.filter(({ at }) => at >= dayAgo).length,
    capped,
    address,
  };
}

/** How many people have never signed in. */
export function neverSignedIn(users = []) {
  return users.filter((user) => !user.last_login_at).length;
}

/**
 * The Central integration acts through a machine account, which the people list leaves
 * out: an actor that is neither a person in that (complete) list nor a deleted account
 * is that account.
 */
export function isMachineActor(entry, people) {
  if (!people || entry?.is_system || !entry?.user?.id || entry.user.deleted) return false;
  return !people.some((person) => person.id === entry.user.id);
}
