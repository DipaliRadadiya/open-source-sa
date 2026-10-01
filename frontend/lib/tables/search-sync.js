/**
 * What the search box should show after the URL changes (e.g. "Clear filters"
 * or the back button), so the input never shows a term that is not applied.
 *   - `urlValue`     what the URL says now
 *   - `seenUrlValue` what it said the last time this box looked
 *   - `value`        what is currently typed
 *
 * Trims only for comparison, so a trailing space mid-typing is kept.
 * Own module so tests can import it without React or next-intl.
 */
export function nextSearchValue({ urlValue, seenUrlValue, value }) {
  // Nothing external changed: this is the box's own typing.
  if (seenUrlValue === urlValue) return value;
  // Adopt the URL unless it matches what is typed (our own debounced push).
  return urlValue !== value.trim() ? urlValue : value;
}
