/**
 * The clock time out of one of the API's `d-m-Y H:i:s` timestamps.
 *
 * The cleaner has no `schedule_time` field to read: its hour is a backend
 * constant (03:00, kept an hour clear of the backups) that the form never asks
 * for, so `next_run_at` is the only place it is ever stated. A card that can
 * only say "every week" is asking the reader to discover the hour by watching
 * for it.
 *
 * Returns "HH:MM" for `scheduleTimeLabel` to render in the reader's clock
 * convention — the same treatment a backup's hour gets, so the two features
 * name an hour the same way.
 *
 * Deliberately string surgery, not `new Date(…)`: the format is day-first, so
 * parsing it as a Date is either wrong (US-style month-first reading) or
 * silently restates it in the browser's timezone — and restating the hour is
 * exactly the fault the timezone label exists to prevent. Anything that is not
 * the expected shape returns null and the caller says nothing rather than
 * something wrong.
 */
export function clockTimeOf(timestamp) {
  const match = /\b(\d{2}):(\d{2})(?::\d{2})?\s*$/.exec(String(timestamp ?? "").trim());
  if (!match) return null;

  const hours = Number(match[1]);
  const minutes = Number(match[2]);
  if (hours > 23 || minutes > 59) return null;

  return `${match[1]}:${match[2]}`;
}
