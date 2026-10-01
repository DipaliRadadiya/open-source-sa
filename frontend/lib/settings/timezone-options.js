/**
 * Flattens `GET /timezones` (`[{ region, zones: [{ value, label, offset }] }]`)
 * into Combobox options. Passing the groups directly crashes the Combobox.
 * The offset (recomputed by the API per request) is appended to the label.
 */
export function timezoneOptions(groups) {
  if (!Array.isArray(groups)) return [];
  return groups.flatMap((group) =>
    (group?.zones ?? []).map((zone) => ({
      value: zone.value,
      label: zone.offset ? `${zone.label} (${zone.offset})` : zone.label,
    })),
  );
}

/**
 * The same list, guaranteed to contain `value`, which may be a zone the API
 * does not list (e.g. set by hand).
 */
export function timezoneOptionsWith(groups, value) {
  const options = timezoneOptions(groups);
  if (!value || options.some((option) => option.value === value)) return options;
  return [{ value, label: value }, ...options];
}

// Linked names from tzdata's `backward` file that `timedatectl` lists and PHP's
// `DateTimeZone::listIdentifiers()` does not. Every `Etc/*` zone and every
// one-word zone but UTC are in the same set, so they are matched by rule below.
const PHP_UNLISTED = new Set([
  "Africa/Timbuktu", "America/Atka", "America/Coral_Harbour", "America/Ensenada",
  "America/Montreal", "America/Nipigon", "America/Pangnirtung", "America/Porto_Acre",
  "America/Rainy_River", "America/Santa_Isabel", "America/Shiprock", "America/Thunder_Bay",
  "America/Virgin", "America/Yellowknife", "Asia/Choibalsan", "Asia/Chongqing", "Asia/Harbin",
  "Asia/Istanbul", "Asia/Kashgar", "Asia/Tel_Aviv", "Atlantic/Jan_Mayen", "Australia/Canberra",
  "Australia/Currie", "Australia/Yancowinna", "Europe/Belfast", "Europe/Nicosia",
  "Europe/Tiraspol", "Pacific/Johnston", "Pacific/Samoa", "Pacific/Yap",
]);

function phpAccepts(zone) {
  if (zone === "UTC") return true;
  if (!zone.includes("/") || zone.startsWith("Etc/")) return false;
  return !PHP_UNLISTED.has(zone);
}

/**
 * Options for a site's `date.timezone`: the API validates against PHP's own
 * list, which lacks e.g. `Etc/UTC`.
 */
export function phpTimezoneOptionsWith(groups, value) {
  const accepted = Array.isArray(groups)
    ? groups.map((group) => ({ ...group, zones: (group?.zones ?? []).filter((zone) => phpAccepts(zone.value)) }))
    : groups;
  return timezoneOptionsWith(accepted, value);
}
