import { minuteOf, timeUsage } from "./frequency.js";

// Chromium's time input ignores lang, so match its locale format.
export function scheduleTimeLabel(time, format) {
  const match = /^(\d{1,2}):(\d{2})$/.exec(String(time ?? "").trim());
  if (!match) return time ?? null;

  const hours = Number(match[1]);
  const minutes = Number(match[2]);
  if (hours > 23 || minutes > 59) return time;

  const at = new Date(2000, 0, 1, hours, minutes);
  return format.dateTime(at, { hour: "numeric", minute: "2-digit" });
}

export function scheduleWhen(target, options, format) {
  if (!target?.schedule_time) return null;
  const usage = timeUsage(options, target.frequency);
  if (usage === "minute") return { minute: minuteOf(target.schedule_time) };
  if (usage === "time") return { time: scheduleTimeLabel(target.schedule_time, format) };
  return null;
}
