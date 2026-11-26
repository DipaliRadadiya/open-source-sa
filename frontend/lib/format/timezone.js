const UTC_ALIASES = /^(Etc\/)?(UTC|UCT|Universal|Zulu|GMT|GMT[+-]0|Greenwich)$/;

export function timezoneLabel(zone) {
  if (!zone) return zone;
  return UTC_ALIASES.test(zone) ? "UTC" : zone;
}

export function wrappableTimezoneLabel(zone) {
  const label = timezoneLabel(zone);
  return typeof label === "string" ? label.replaceAll("/", "/\u200b") : label;
}
