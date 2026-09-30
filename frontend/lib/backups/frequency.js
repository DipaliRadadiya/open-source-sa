/**
 * Reading `GET /backup-targets/options` for the form and the lists.
 *
 * The labels, hints and the list itself come from the API. What it does not
 * send is how far apart two runs are, which the retention hint needs: on
 * `hourly` a retention of 7 is seven HOURS of history, and "about 7 days"
 * would be a promise the schedule cannot keep. A value this file does not
 * recognise gets no span, and the hint falls back to counting backups.
 */

/** The API's entry for one frequency, or null. */
export function frequencyOption(options, value) {
  return options?.frequencies.find((f) => f.value === value) ?? null;
}

/**
 * Which picker a frequency needs: "minute", "time", or null (none).
 * Undefined when the options are missing or do not know the value.
 */
export function timeUsage(options, value) {
  const option = frequencyOption(options, value);
  return option ? option.time : undefined;
}

/** The schedules a person picks from. Manual is the Automatic switch being off. */
export function scheduledFrequencies(options) {
  return options?.frequencies.filter((f) => f.time !== null) ?? [];
}

const DAY = 24;

/** Hours between two runs, or null when it cannot be told from the value. */
export function hoursBetweenRuns(value) {
  if (value === "hourly") return 1;
  const every = /^every_(\d+)_hours$/.exec(value ?? "");
  if (every) return Number(every[1]);
  if (value === "daily") return DAY;
  if (value === "weekly") return 7 * DAY;
  if (value === "monthly") return 30 * DAY;
  return null;
}

/**
 * How much history `count` backups cover: `{ unit: "hours" | "days", amount }`,
 * or null when the spacing is unknown.
 *
 * Hours below a day, because "about 0 days" is what a day count says about
 * seven hourly backups.
 */
export function historySpan(value, count) {
  const hours = hoursBetweenRuns(value);
  if (!hours || !(count > 0)) return null;
  const total = hours * count;
  return total < DAY ? { unit: "hours", amount: total } : { unit: "days", amount: Math.round(total / DAY) };
}

const TIME = /^(\d{1,2}):(\d{2})$/;

/** "14:30" → "30". */
export function minuteOf(time) {
  return TIME.exec(String(time ?? ""))?.[2] ?? "00";
}

/**
 * The stored time with only its minute changed.
 *
 * `hourly` reads only the minute, but the hour is kept rather than zeroed so
 * that switching back to a daily schedule returns to the hour it had.
 */
export function withMinute(time, minute) {
  const hour = TIME.exec(String(time ?? ""))?.[1] ?? "00";
  const n = Math.min(Math.max(Number.parseInt(minute, 10) || 0, 0), 59);
  return `${hour.padStart(2, "0")}:${String(n).padStart(2, "0")}`;
}

/**
 * The API's backup types, the full backup first.
 *
 * The API lists files, database, full. The form leads with the one that
 * restores a whole site, because it is the one a first-timer should pick.
 */
export function orderedTypes(options) {
  const types = options?.types ?? [];
  return [...types.filter((t) => t.value === "full"), ...types.filter((t) => t.value !== "full")];
}
