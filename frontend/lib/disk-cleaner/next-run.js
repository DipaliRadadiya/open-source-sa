// "HH:MM" from a `d-m-Y H:i:s` timestamp. String parsing, not `new Date(…)`:
// the format is day-first and a Date would misread or shift it.
export function clockTimeOf(timestamp) {
  const match = /\b(\d{2}):(\d{2})(?::\d{2})?\s*$/.exec(String(timestamp ?? "").trim());
  if (!match) return null;

  const hours = Number(match[1]);
  const minutes = Number(match[2]);
  if (hours > 23 || minutes > 59) return null;

  return `${match[1]}:${match[2]}`;
}
