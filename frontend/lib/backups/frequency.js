export function frequencyOption(options, value) {
  return options?.frequencies.find((f) => f.value === value) ?? null;
}

export function timeUsage(options, value) {
  const option = frequencyOption(options, value);
  return option ? option.time : undefined;
}

export function scheduledFrequencies(options) {
  return options?.frequencies.filter((f) => f.time !== null) ?? [];
}

const DAY = 24;

export function hoursBetweenRuns(value) {
  if (value === "hourly") return 1;
  const every = /^every_(\d+)_hours$/.exec(value ?? "");
  if (every) return Number(every[1]);
  if (value === "daily") return DAY;
  if (value === "weekly") return 7 * DAY;
  if (value === "monthly") return 30 * DAY;
  return null;
}

export function historySpan(value, count) {
  const hours = hoursBetweenRuns(value);
  if (!hours || !(count > 0)) return null;
  const total = hours * count;
  return total < DAY ? { unit: "hours", amount: total } : { unit: "days", amount: Math.round(total / DAY) };
}

const TIME = /^(\d{1,2}):(\d{2})$/;

export function minuteOf(time) {
  return TIME.exec(String(time ?? ""))?.[2] ?? "00";
}

export function withMinute(time, minute) {
  const hour = TIME.exec(String(time ?? ""))?.[1] ?? "00";
  const n = Math.min(Math.max(Number.parseInt(minute, 10) || 0, 0), 59);
  return `${hour.padStart(2, "0")}:${String(n).padStart(2, "0")}`;
}

export const DEFAULT_TYPE = { hosted: "full", container: "volumes_config" };

export function orderedTypes(options, container = false) {
  const family = container ? "container" : "hosted";
  const types = (options?.types ?? []).filter((t) => (t.family ?? "hosted") === family);
  const first = DEFAULT_TYPE[family];
  return [...types.filter((t) => t.value === first), ...types.filter((t) => t.value !== first)];
}

export function frequencyLabel(target, paused) {
  const title = target?.frequency_title ?? target?.frequency;
  return target && target.enabled === false && target.frequency !== "manual" ? paused(title) : title;
}
