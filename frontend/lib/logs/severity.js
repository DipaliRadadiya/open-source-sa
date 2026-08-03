// Purely presentational tinting. The API returns raw lines; nothing here
// changes meaning, so an unrecognised line simply renders untinted rather than
// being guessed at.

const LEVEL_PATTERNS = [
  { level: "error", re: /\b(error|critical|crit|fatal|emerg|alert|panic)\b/i },
  { level: "warn", re: /\b(warn|warning)\b/i },
  { level: "notice", re: /\b(debug|notice|trace)\b/i },
  { level: "info", re: /\b(info|information)\b/i },
];

// Access logs carry no level word — the HTTP status is the signal.
const ACCESS_STATUS = /"\s(\d{3})\s|\s(\d{3})\s\d+\s*$|"\s\d+\s(\d{3})\s/;

function accessLevel(line) {
  const m = line.match(ACCESS_STATUS);
  const code = Number(m?.[1] ?? m?.[2] ?? m?.[3]);
  if (!code) return null;
  if (code >= 500) return "error";
  if (code >= 400) return "warn";
  if (code >= 300) return "notice";
  return "success";
}

/** Returns "error" | "warn" | "info" | "notice" | "success" | null. */
export function lineLevel(line, group) {
  if (!line) return null;
  if (group === "web") {
    const fromStatus = accessLevel(line);
    if (fromStatus) return fromStatus;
  }
  return LEVEL_PATTERNS.find((p) => p.re.test(line))?.level ?? null;
}

// Severity filter buckets. "warnings" includes errors: nobody asking for
// warnings wants the errors hidden.
export const SEVERITY_FILTERS = ["all", "errors", "warnings"];

const IN_BUCKET = {
  errors: new Set(["error"]),
  warnings: new Set(["error", "warn"]),
};

/**
 * Client-side display filter over the loaded buffer. Unlike grep (server-side,
 * whole file) this only ever sees the lines we hold — which is why it can run
 * while tailing, and why the UI states the count as "of N loaded".
 */
export function matchesSeverity(line, group, filter) {
  const bucket = IN_BUCKET[filter];
  if (!bucket) return true;
  return bucket.has(lineLevel(line, group));
}

// Text colour only — no backgrounds. A log is a wall of text; tinted rows would
// fight the content instead of guiding the eye to the few lines that matter.
// These are the console-specific hues, lifted for contrast on the dark canvas.
export const LEVEL_CLASS = {
  error: "text-console-error",
  warn: "text-console-warning",
  success: "text-console-success",
  notice: "text-console-muted",
  info: "",
};

/**
 * Splits a line around every case-insensitive occurrence of `term` so the match
 * can be marked. Returns [{ text, match }] — one entry when there's no term.
 */
export function splitOnTerm(line, term) {
  const needle = term?.trim();
  if (!needle) return [{ text: line, match: false }];

  const parts = [];
  const lower = line.toLowerCase();
  const target = needle.toLowerCase();
  let at = 0;

  for (;;) {
    const found = lower.indexOf(target, at);
    if (found === -1) break;
    if (found > at) parts.push({ text: line.slice(at, found), match: false });
    parts.push({ text: line.slice(found, found + target.length), match: true });
    at = found + target.length;
  }
  if (at < line.length) parts.push({ text: line.slice(at), match: false });
  return parts.length ? parts : [{ text: line, match: false }];
}
