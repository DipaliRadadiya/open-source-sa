import { minuteOf, timeUsage } from "./frequency.js";

/**
 * A stored 24-hour "HH:MM" formatted in the reader's locale, to match the
 * native `<input type="time">` (whose format `lang` cannot override in
 * Chromium). Any other shape is returned unchanged.
 */
export function scheduleTimeLabel(time, format) {
  const match = /^(\d{1,2}):(\d{2})$/.exec(String(time ?? "").trim());
  if (!match) return time ?? null;

  const hours = Number(match[1]);
  const minutes = Number(match[2]);
  if (hours > 23 || minutes > 59) return time;

  // The date is a carrier, never shown — only the time fields are formatted.
  const at = new Date(2000, 0, 1, hours, minutes);
  return format.dateTime(at, { hour: "numeric", minute: "2-digit" });
}

/**
 * When a target runs, as far as its frequency uses the stored time:
 * `{ minute: "30" }` for minute-only schedules (hourly), `{ time }` for ones
 * that run at an hour, null for none or when the options are unknown.
 */
export function scheduleWhen(target, options, format) {
  if (!target?.schedule_time) return null;
  const usage = timeUsage(options, target.frequency);
  if (usage === "minute") return { minute: minuteOf(target.schedule_time) };
  if (usage === "time") return { time: scheduleTimeLabel(target.schedule_time, format) };
  return null;
}
