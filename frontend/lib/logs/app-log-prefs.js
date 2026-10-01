import { normalizeLineCount } from "../schemas/log.js";

/**
 * The reader's Live and line-count choices on an application's Logs page,
 * kept in cookies. Live is stored per source: following a quiet error log is
 * not consent to tail the busiest access log.
 */
export const APP_LOG_FOLLOW_COOKIE = "sv_app_logs_follow";
export const APP_LOG_LINES_COOKIE = "sv_app_logs_lines";

// Sources that follow by default: low-volume, and read for "what just broke".
export const AUTO_FOLLOW_KEYS = new Set(["error", "application", "application_error"]);

// "error:on,access:off" → { error: true, access: false }
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
    // A blocked cookie costs the preference, nothing else.
  }
}
