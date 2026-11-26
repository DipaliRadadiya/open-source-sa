const DAY_MS = 24 * 60 * 60 * 1000;

export const SIGN_IN_ACTIONS = ["logged_in", "login_failed", "login_failed_unknown"];

export const FAILED_SIGN_IN_ALERT = 10;

export function parseActivityTime(value) {
  const m = typeof value === "string" ? value.match(/^(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2}):(\d{2})$/) : null;
  if (!m) return null;
  const [, d, mo, y, h, mi, s] = m.map(Number);
  return Date.UTC(y, mo - 1, d, h, mi, s);
}

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

export function neverSignedIn(users = []) {
  return users.filter((user) => !user.last_login_at).length;
}

export function isMachineActor(entry, people) {
  if (!people || entry?.is_system || !entry?.user?.id || entry.user.deleted) return false;
  return !people.some((person) => person.id === entry.user.id);
}
