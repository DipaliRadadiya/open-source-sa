/**
 * Seconds → the words a person would use.
 *
 * The API talks in seconds and the config file stores seconds, so the input
 * has to stay a number. But "600" tells you nothing at a glance and "1 day"
 * tells you everything, so every raw number on this page is echoed in words.
 */
export function humanDuration(t, seconds) {
  const n = Number(seconds);
  if (!Number.isFinite(n) || n <= 0) return null;
  if (n < 60) return t("settings.humanSeconds", { count: n });
  if (n < 3600) return t("settings.humanMinutes", { count: Math.round(n / 60) });
  if (n < 86400) return t("settings.humanHours", { count: Math.round(n / 3600) });
  return t("settings.humanDays", { count: Math.round(n / 86400) });
}
