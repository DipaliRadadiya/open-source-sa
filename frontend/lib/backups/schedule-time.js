import { minuteOf, timeUsage } from "./frequency.js";

/**
 * A stored "HH:MM" shown the way the reader's clock shows times.
 *
 * The schedule is set with a native `<input type="time">`, which renders in the
 * browser's own locale — "02:30 PM" on a US machine — while the card printed
 * the stored string, "14:30". Two clock formats for one value, a few hundred
 * pixels apart.
 *
 * The input cannot be forced: `lang` does not override it in Chromium, which I
 * checked. So the displayed value follows the locale instead, which is what
 * every other time in the panel already does and what makes the two agree.
 *
 * The API stores and validates 24-hour "HH:MM" throughout; only the display
 * moves. A value that is not that shape is returned unchanged rather than
 * turned into "Invalid Date".
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
 * When a target runs, as far as its frequency uses the stored time.
 *
 * `{ minute: "30" }` for a schedule that reads only the minute (hourly — its
 * "14:30" means half past every hour, and printing 2:30 PM would name one run
 * out of twenty-four), `{ time }` for one that runs at an hour, null for none.
 * Also null when the options could not be read: the frequency's title alone
 * is less than the full answer, a guessed hour would be a wrong one.
 */
export function scheduleWhen(target, options, format) {
  if (!target?.schedule_time) return null;
  const usage = timeUsage(options, target.frequency);
  if (usage === "minute") return { minute: minuteOf(target.schedule_time) };
  if (usage === "time") return { time: scheduleTimeLabel(target.schedule_time, format) };
  return null;
}
