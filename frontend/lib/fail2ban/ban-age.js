// fail2ban's own "YYYY-MM-DD HH:MM:SS", in the server's clock.
const STAMP = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/;

function wallClockMs(value) {
  const m = String(value ?? "").match(STAMP);
  if (!m) return null;
  const [, y, mo, d, h, mi, s] = m.map(Number);
  return Date.UTC(y, mo - 1, d, h, mi, s);
}

/**
 * Seconds since the ban started, or null when it cannot be known.
 *
 * Not `now - banned_at`: banned_at is the SERVER's wall clock with no zone, so reading it
 * in the browser's zone is off by the difference (5½ h in India). The ban's length
 * (expires_at - banned_at, both the same clock) minus the server's own `seconds_left`
 * needs no zone at all. Permanent bans have no expiry, so no age.
 */
export function banAgeSeconds({ banned_at: since, expires_at: expires, seconds_left: left }) {
  const start = wallClockMs(since);
  const end = wallClockMs(expires);
  if (start === null || end === null || typeof left !== "number") return null;
  const age = (end - start) / 1000 - left;
  return age >= 0 ? age : null;
}
