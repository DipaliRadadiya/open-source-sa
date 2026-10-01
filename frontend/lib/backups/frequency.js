// The API does not send the spacing between runs, so it is derived here.

/** The API's entry for one frequency, or null. */
export function frequencyOption(options, value) {
  return options?.frequencies.find((f) => f.value === value) ?? null;
}

// "minute", "time" or null (none); undefined when the options do not know the value.
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

// `{ unit: "hours" | "days", amount }`, or null when spacing is unknown. Hours below a day.
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

// The hour is kept so switching back from `hourly` to daily restores it.
export function withMinute(time, minute) {
  const hour = TIME.exec(String(time ?? ""))?.[1] ?? "00";
  const n = Math.min(Math.max(Number.parseInt(minute, 10) || 0, 0), 59);
  return `${hour.padStart(2, "0")}:${String(n).padStart(2, "0")}`;
}

/** The API's backup types with `full` first, the sensible default. */
export function orderedTypes(options) {
  const types = options?.types ?? [];
  return [...types.filter((t) => t.value === "full"), ...types.filter((t) => t.value !== "full")];
}
