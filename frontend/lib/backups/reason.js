/**
 * A failure reason that is safe to show a person.
 *
 * The backend builds these as `__('backup.errors.'.$reason)`, which resolves to
 * prose for every reason it knows. When it does not know one — a reason written
 * as free text rather than a key — Laravel returns the KEY, and the panel ends
 * up telling a user their backup failed because of
 * "backup.errors.storage destination unreachable".
 *
 * A raw key is worse than no detail: it looks like a crash and explains
 * nothing. So anything still carrying the lookup prefix is treated as missing
 * and replaced with the generic line.
 *
 * @param {string|null|undefined} title  `reason_title` from the API
 * @param {string} fallback              translated generic message
 * @returns {string|null}
 */
export function reasonText(title, fallback) {
  if (!title) return null;
  return /^backup\.(errors|restore_errors)\./.test(title) ? fallback : title;
}
