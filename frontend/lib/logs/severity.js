const LEVEL_PATTERNS = [
  { level: "error", re: /\b(error|critical|crit|fatal|emerg|alert|panic)\b/i },
  { level: "warn", re: /\b(warn|warning)\b/i },
  { level: "notice", re: /\b(debug|notice|trace)\b/i },
  { level: "info", re: /\b(info|information)\b/i },
];

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

export function lineLevel(line, group) {
  if (!line) return null;
  if (group === "web") {
    const fromStatus = accessLevel(line);
    if (fromStatus) return fromStatus;
  }
  return LEVEL_PATTERNS.find((p) => p.re.test(line))?.level ?? null;
}

export function isAccessLog(key) {
  return typeof key === "string" && (/(^|_)access(_ssl)?$/.test(key) || key === "waf_detect");
}

export const SEVERITY_FILTERS = ["all", "errors", "warnings"];

const IN_BUCKET = {
  errors: new Set(["error"]),
  warnings: new Set(["error", "warn"]),
};

export function matchesSeverity(line, group, filter) {
  const bucket = IN_BUCKET[filter];
  if (!bucket) return true;
  return bucket.has(lineLevel(line, group));
}

export const LEVEL_CLASS = {
  error: "text-console-error",
  warn: "text-console-warning",
  success: "text-console-success",
  notice: "text-console-muted",
  info: "",
};

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
