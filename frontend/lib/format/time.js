export function safeTimeZone(timeZone) {
  if (!timeZone || typeof timeZone !== "string") return undefined;
  try {
    new Intl.DateTimeFormat("en", { timeZone });
    return timeZone;
  } catch {
    return undefined;
  }
}

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
