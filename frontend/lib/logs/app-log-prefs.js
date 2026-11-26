import { normalizeLineCount } from "../schemas/log.js";

export const APP_LOG_FOLLOW_COOKIE = "sv_app_logs_follow";
export const APP_LOG_LINES_COOKIE = "sv_app_logs_lines";

export const AUTO_FOLLOW_KEYS = new Set(["error", "error_ssl", "application", "application_error"]);

export function parseFollowPrefs(value) {
  const prefs = {};
  for (const part of typeof value === "string" ? value.split(",") : []) {
    const [key, state] = part.split(":");
    if (/^[\w-]+$/.test(key ?? "") && (state === "on" || state === "off")) prefs[key] = state === "on";
  }
  return prefs;
}

export function serializeFollowPrefs(prefs) {
  return Object.entries(prefs)
    .map(([key, on]) => `${key}:${on ? "on" : "off"}`)
    .join(",");
}

export function followFor(key, prefs) {
  return key in prefs ? prefs[key] : AUTO_FOLLOW_KEYS.has(key);
}

export function parseLinesPref(value, fallback) {
  const n = Number(value);
  return Number.isFinite(n) && n > 0 ? normalizeLineCount(n) : fallback;
}

export function writeCookie(name, value) {
  try {
    document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${60 * 60 * 24 * 365}; samesite=lax`;
  } catch {
    // storage blocked; ignore
  }
}
