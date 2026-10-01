// Mirrors the backend's parser (dragonmantank/cron-expression), incl. 7 as Sunday
// and day-of-month OR day-of-week. Null for anything unreadable; validation takes over.

const MACROS = {
  "@yearly": "0 0 1 1 *",
  "@annually": "0 0 1 1 *",
  "@monthly": "0 0 1 * *",
  "@weekly": "0 0 * * 0",
  "@daily": "0 0 * * *",
  "@midnight": "0 0 * * *",
  "@hourly": "0 * * * *",
};

const MONTH_NAMES = ["jan", "feb", "mar", "apr", "may", "jun", "jul", "aug", "sep", "oct", "nov", "dec"];
const DAY_NAMES = ["sun", "mon", "tue", "wed", "thu", "fri", "sat"];

const FIELDS = [
  { min: 0, max: 59 },
  { min: 0, max: 23 },
  { min: 1, max: 31 },
  { min: 1, max: 12, names: MONTH_NAMES, offset: 1 },
  { min: 0, max: 7, names: DAY_NAMES, offset: 0 },
];

function toNumber(token, field) {
  const lower = token.toLowerCase();
  const named = field.names?.indexOf(lower);
  if (named !== undefined && named >= 0) return named + field.offset;
  if (!/^\d+$/.test(token)) return NaN;
  return Number(token);
}

function parseField(text, field) {
  const values = new Set();
  for (const part of text.split(",")) {
    const [range, stepText] = part.split("/");
    const step = stepText === undefined ? 1 : Number(stepText);
    if (!Number.isInteger(step) || step < 1 || (stepText !== undefined && !/^\d+$/.test(stepText))) return null;

    let from;
    let to;
    if (range === "*") {
      from = field.min;
      to = field.max;
    } else if (range.includes("-")) {
      const [a, b] = range.split("-");
      from = toNumber(a, field);
      to = toNumber(b, field);
    } else {
      from = toNumber(range, field);
      // "5/15" means from 5 to the end in steps of 15.
      to = stepText === undefined ? from : field.max;
    }
    if (!Number.isInteger(from) || !Number.isInteger(to)) return null;
    if (from < field.min || to > field.max || from > to) return null;
    for (let v = from; v <= to; v += step) values.add(v);
  }
  return values;
}

// Weekday 7 is folded into 0.
export function parseCron(expression) {
  if (typeof expression !== "string") return null;
  const trimmed = expression.trim();
  const source = MACROS[trimmed.toLowerCase()] ?? trimmed;
  const parts = source.split(/\s+/);
  if (parts.length !== 5) return null;

  const sets = parts.map((text, i) => parseField(text, FIELDS[i]));
  if (sets.some((s) => !s || s.size === 0)) return null;

  const [minutes, hours, days, months, weekdaysRaw] = sets;
  const weekdays = new Set([...weekdaysRaw].map((d) => d % 7));
  return {
    minutes,
    hours,
    days,
    months,
    weekdays,
    // Restricted = not a bare star. With both restricted, cron runs when
    // EITHER matches; with one of them a star, only the other counts.
    daysRestricted: parts[2] !== "*",
    weekdaysRestricted: parts[4] !== "*",
    minuteText: parts[0],
    hourText: parts[1],
  };
}

function dayMatches(cron, day, weekday) {
  const byDay = cron.days.has(day);
  const byWeekday = cron.weekdays.has(weekday);
  if (cron.daysRestricted && cron.weekdaysRestricted) return byDay || byWeekday;
  if (cron.daysRestricted) return byDay;
  if (cron.weekdaysRestricted) return byWeekday;
  return true;
}

/** The current wall-clock time in `timeZone`, as a Date whose UTC fields hold it. */
export function wallClockNow(timeZone, now = new Date()) {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat("en-US", {
      timeZone: timeZone || "UTC",
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hourCycle: "h23",
    })
      .formatToParts(now)
      .map((p) => [p.type, p.value]),
  );
  return new Date(
    Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day), Number(parts.hour), Number(parts.minute), Number(parts.second)),
  );
}

// Wall-clock Dates: the UTC fields are the server's local time. Skips whole
// months, days and hours that cannot match, so even a yearly job is found quickly.
export function nextRuns(cron, from, count = 3) {
  const runs = [];
  if (!cron) return runs;
  const t = new Date(from.getTime());
  t.setUTCSeconds(0, 0);
  t.setUTCMinutes(t.getUTCMinutes() + 1);

  // Five years covers any valid expression (29 Feb on a Monday, say).
  const limit = from.getTime() + 5 * 366 * 24 * 3600 * 1000;
  while (runs.length < count && t.getTime() < limit) {
    if (!cron.months.has(t.getUTCMonth() + 1)) {
      t.setUTCMonth(t.getUTCMonth() + 1, 1);
      t.setUTCHours(0, 0, 0, 0);
      continue;
    }
    if (!dayMatches(cron, t.getUTCDate(), t.getUTCDay())) {
      t.setUTCDate(t.getUTCDate() + 1);
      t.setUTCHours(0, 0, 0, 0);
      continue;
    }
    if (!cron.hours.has(t.getUTCHours())) {
      t.setUTCHours(t.getUTCHours() + 1, 0, 0, 0);
      continue;
    }
    if (!cron.minutes.has(t.getUTCMinutes())) {
      t.setUTCMinutes(t.getUTCMinutes() + 1, 0, 0);
      continue;
    }
    runs.push(new Date(t.getTime()));
    t.setUTCMinutes(t.getUTCMinutes() + 1, 0, 0);
  }
  return runs;
}

const sorted = (set) => [...set].sort((a, b) => a - b);
const isAll = (set, field) => set.size === field.max - field.min + 1;

function stepOf(text) {
  const m = /^\*\/(\d+)$/.exec(text);
  return m ? Number(m[1]) : null;
}

function contiguous(values) {
  return values.length > 1 && values.every((v, i) => i === 0 || v === values[i - 1] + 1);
}

// `time` is null when the pattern is too irregular for a sentence.
export function describeCron(cron) {
  if (!cron) return null;
  const minutes = sorted(cron.minutes);
  const hours = sorted(cron.hours);
  const allHours = isAll(cron.hours, FIELDS[1]);

  let time = null;
  if (isAll(cron.minutes, FIELDS[0]) && allHours) time = { kind: "everyMinute" };
  else if (stepOf(cron.minuteText) && allHours) time = { kind: "everyMinutes", n: stepOf(cron.minuteText) };
  else if (minutes.length === 1 && allHours) time = { kind: "hourly", minute: minutes[0] };
  else if ((isAll(cron.minutes, FIELDS[0]) || stepOf(cron.minuteText)) && contiguous(hours)) {
    // From the first run to the last one — "9-17" with */10 ends at 17:50, not 17:00.
    time = {
      kind: "everyBetween",
      n: stepOf(cron.minuteText) ?? 1,
      from: [hours[0], minutes[0]],
      to: [hours[hours.length - 1], minutes[minutes.length - 1]],
    };
  } else if (minutes.length === 1 && stepOf(cron.hourText) && stepOf(cron.hourText) > 1) {
    time = { kind: "everyHours", n: stepOf(cron.hourText), minute: minutes[0] };
  } else if (minutes.length * hours.length <= 6) {
    time = { kind: "at", times: hours.flatMap((h) => minutes.map((m) => [h, m])) };
  }

  const weekdays = sorted(cron.weekdays);
  return {
    time,
    days: cron.daysRestricted ? sorted(cron.days) : null,
    weekdays: cron.weekdaysRestricted ? weekdays : null,
    weekdayRange: cron.weekdaysRestricted && weekdays.length >= 3 && contiguous(weekdays) ? [weekdays[0], weekdays[weekdays.length - 1]] : null,
    months: isAll(cron.months, FIELDS[3]) ? null : sorted(cron.months),
  };
}

// `next_run_at` ("28-09-2026 05:01:00", server wall clock) as an instant in
// `timeZone`, or null.
export function serverTimeToEpoch(text, timeZone) {
  const m = /^(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2}):(\d{2})$/.exec(text ?? "");
  if (!m) return null;
  const wall = Date.UTC(Number(m[3]), Number(m[2]) - 1, Number(m[1]), Number(m[4]), Number(m[5]), Number(m[6]));
  // The zone's offset at that moment, found by asking what wall time the
  // guessed instant shows there; twice, so a DST edge between the two settles.
  let epoch = wall;
  for (let i = 0; i < 2; i++) epoch = wall - (wallClockNow(timeZone, new Date(epoch)).getTime() - epoch);
  return epoch;
}
