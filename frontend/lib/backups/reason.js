// An unknown reason comes back as the raw `backup.errors.<reason>` key, replaced with `fallback`.
export function reasonText(title, fallback) {
  if (!title) return null;
  return /^backup\.(errors|restore_errors)\./.test(title) ? fallback : title;
}
