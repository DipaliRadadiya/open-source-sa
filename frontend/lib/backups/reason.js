/**
 * A failure reason that is safe to show. The backend translates
 * `backup.errors.<reason>`; an unknown reason comes back as the raw key, which
 * is replaced with the generic `fallback`.
 *
 * @param {string|null|undefined} title  `reason_title` from the API
 * @param {string} fallback              translated generic message
 * @returns {string|null}
 */
export function reasonText(title, fallback) {
  if (!title) return null;
  return /^backup\.(errors|restore_errors)\./.test(title) ? fallback : title;
}
