// Keeps the box in step with URL changes (Clear filters, back). Trims only for
// comparison, so a trailing space mid-typing is kept.
export function nextSearchValue({ urlValue, seenUrlValue, value }) {
  // Nothing external changed: this is the box's own typing.
  if (seenUrlValue === urlValue) return value;
  // Adopt the URL unless it matches what is typed (our own debounced push).
  return urlValue !== value.trim() ? urlValue : value;
}
