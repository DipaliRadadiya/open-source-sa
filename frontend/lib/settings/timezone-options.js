/**
 * Flattens `GET /timezones` into a flat option list for a Combobox.
 *
 * The API answers grouped by region — `[{ region, zones: [{ value, label,
 * offset }] }]` — which the grouped <Select> on Settings → Server renders
 * directly. A Combobox takes a flat list, and passing the groups to one
 * straight puts a region OBJECT where React expects a label, which throws the
 * whole page into its error boundary the moment the list opens. That is a real
 * bug this shape invited twice, so the flattening lives here now.
 *
 * The offset rides along in the label because it is the thing people check a
 * timezone against, and the API recomputes it per request so it stays correct
 * across daylight saving.
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
 * The same list, guaranteed to contain `value`.
 *
 * A pool tuned by hand can hold a zone the API's list does not offer. Without
 * this the field falls back to its placeholder and hides the value it is about
 * to save — the reader sees an empty picker over a server that is set.
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
 * The list for a site's `date.timezone`, which the API validates against PHP's
 * own list rather than the server's. `Etc/UTC` is the visible case: it read as
 * a second "UTC" and failed to save while the other one worked.
 */
export function phpTimezoneOptionsWith(groups, value) {
  const accepted = Array.isArray(groups)
    ? groups.map((group) => ({ ...group, zones: (group?.zones ?? []).filter((zone) => phpAccepts(zone.value)) }))
    : groups;
  return timezoneOptionsWith(accepted, value);
}
