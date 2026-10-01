// Dashboard clock times use the monitored server's timezone (facts.timezone).
// Validated first: Intl throws a RangeError on an unknown zone.

export function safeTimeZone(timeZone) {
  if (!timeZone || typeof timeZone !== "string") return undefined;
  try {
    new Intl.DateTimeFormat("en", { timeZone });
    return timeZone;
  } catch {
    return undefined;
  }
}

/**
 * Builds a `(value) => string` clock formatter bound to a timezone, for use as
 * a Recharts tick/label formatter. Falls back to the app-wide zone when the
 * server didn't report a usable one.
 */
export function clockFormatter(format, timeZone, options = {}) {
  const zone = safeTimeZone(timeZone);
  return (value) => {
    if (value == null || value === "") return "";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "";
    return format.dateTime(date, {
      hour: "2-digit",
      minute: "2-digit",
      ...options,
      ...(zone ? { timeZone: zone } : null),
    });
  };
}
