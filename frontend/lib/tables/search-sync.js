export function nextSearchValue({ urlValue, seenUrlValue, value }) {
  if (seenUrlValue === urlValue) return value;
  return urlValue !== value.trim() ? urlValue : value;
}
