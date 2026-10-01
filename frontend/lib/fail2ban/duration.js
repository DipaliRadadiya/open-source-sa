/** Seconds → words ("1 day"), echoed beside raw-seconds inputs. */
export function humanDuration(t, seconds) {
  const n = Number(seconds);
  if (!Number.isFinite(n) || n <= 0) return null;
  if (n < 60) return t("settings.humanSeconds", { count: n });
  if (n < 3600) return t("settings.humanMinutes", { count: Math.round(n / 60) });
  if (n < 86400) return t("settings.humanHours", { count: Math.round(n / 3600) });
  return t("settings.humanDays", { count: Math.round(n / 86400) });
}
