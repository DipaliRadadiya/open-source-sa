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
