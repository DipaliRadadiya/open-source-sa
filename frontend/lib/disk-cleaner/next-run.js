/**
 * The "HH:MM" out of one of the API's `d-m-Y H:i:s` timestamps. The cleaner's
 * hour is a backend constant, so `next_run_at` is the only place it is stated.
 *
 * String parsing, not `new Date(…)`: the format is day-first, and a Date would
 * misread it or shift it into the browser's timezone. Unexpected shapes return null.
 */
export function clockTimeOf(timestamp) {
  const match = /\b(\d{2}):(\d{2})(?::\d{2})?\s*$/.exec(String(timestamp ?? "").trim());
  if (!match) return null;

  const hours = Number(match[1]);
  const minutes = Number(match[2]);
  if (hours > 23 || minutes > 59) return null;

  return `${match[1]}:${match[2]}`;
}
