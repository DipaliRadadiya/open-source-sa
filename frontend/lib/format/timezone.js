// Display only: the server reports its zone as a tz id, and "Etc/UTC" reads like a path.
// Calculations keep the raw id.
const UTC_ALIASES = /^(Etc\/)?(UTC|UCT|Universal|Zulu|GMT|GMT[+-]0|Greenwich)$/;

export function timezoneLabel(zone) {
  if (!zone) return zone;
  return UTC_ALIASES.test(zone) ? "UTC" : zone;
}

// For a narrow column header: the label may break after each "/" (a zero-width space), so
// "America/Argentina/Buenos_Aires" wraps inside its column instead of running into the next.
export function wrappableTimezoneLabel(zone) {
  const label = timezoneLabel(zone);
  return typeof label === "string" ? label.replaceAll("/", "/\u200b") : label;
}
