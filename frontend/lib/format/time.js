// Clock times on the dashboard are shown in the monitored server's timezone
// (facts.timezone) — that's the clock its logs and `top` output use. The value
// comes from the API, so it is validated before Intl is handed it: an unknown
// zone throws a RangeError and would take the whole card down.

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
