// Splits a raw line into the three parts almost every log format shares:
// when it happened, how bad it is, and what happened. Colouring those
// separately is what turns a wall of identical text into something scannable —
// without it, a file of pure INFO lines reads as one grey block.
//
// Conservative by design: if a part isn't confidently matched it stays in the
// message, so nothing is ever mislabelled.

// 2026-07-29 04:16:22,301 | 2026-07-29T04:16:22 | Jul 29 04:37:16 | [29/Jul/2026:04:38:03 +0000]
const TIMESTAMP = new RegExp(
  "^(" +
    "\\d{4}-\\d{2}-\\d{2}[T ]\\d{2}:\\d{2}:\\d{2}(?:[.,]\\d+)?(?:\\s?[+-]\\d{2}:?\\d{2}|Z)?" +
    "|[A-Z][a-z]{2}\\s+\\d{1,2}\\s+\\d{2}:\\d{2}:\\d{2}" +
    "|\\[\\d{2}/[A-Za-z]{3}/\\d{4}(?::\\d{2}){3}\\s?[+-]\\d{4}\\]" +
    ")\\s*",
);

// A level word, optionally bracketed, right after the timestamp.
const LEVEL = /^(\[)?(EMERG|ALERT|CRIT(?:ICAL)?|FATAL|ERROR|ERR|WARN(?:ING)?|NOTICE|INFO|DEBUG|TRACE)(\])?\s*/i;

const LEVEL_TO_KEY = {
  emerg: "error",
  alert: "error",
  crit: "error",
  critical: "error",
  fatal: "error",
  error: "error",
  err: "error",
  warn: "warn",
  warning: "warn",
  notice: "notice",
  debug: "notice",
  trace: "notice",
  info: "info",
};

/**
 * @returns {{ time: string|null, level: string|null, levelKey: string|null, message: string }}
 */
export function tokenizeLine(line) {
  if (!line) return { time: null, level: null, levelKey: null, message: line ?? "" };

  let rest = line;
  const timeMatch = rest.match(TIMESTAMP);
  const time = timeMatch ? timeMatch[0].trimEnd() : null;
  if (timeMatch) rest = rest.slice(timeMatch[0].length);

  const levelMatch = rest.match(LEVEL);
  const level = levelMatch ? `${levelMatch[1] ?? ""}${levelMatch[2]}${levelMatch[3] ?? ""}` : null;
  const levelKey = levelMatch ? LEVEL_TO_KEY[levelMatch[2].toLowerCase()] ?? null : null;
  if (levelMatch) rest = rest.slice(levelMatch[0].length);

  return { time, level, levelKey, message: rest };
}
