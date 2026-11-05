// Display only: the server reports its zone as a tz id, and "Etc/UTC" reads like a path.
// Calculations keep the raw id.
const UTC_ALIASES = /^(Etc\/)?(UTC|UCT|Universal|Zulu|GMT|GMT[+-]0|Greenwich)$/;

export function timezoneLabel(zone) {
  if (!zone) return zone;
  return UTC_ALIASES.test(zone) ? "UTC" : zone;
}
