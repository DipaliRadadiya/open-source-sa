/**
 * Which sites actually have a database, for the backup form.
 *
 * A "files + database" backup of a site with no database succeeds and quietly
 * contains no database — the backend's own step returns early and records an
 * empty list. Nothing downstream says so, so the form has to, and to say it
 * the form needs to know how many databases the chosen site really has.
 *
 * `/databases` has no application filter, so this groups the list the panel
 * already fetches. Every row carries `application_id`.
 */
export function countByApplication(databases = []) {
  const counts = {};

  for (const database of databases ?? []) {
    const id = database?.application_id;
    // Server-level databases belong to no site and must not be credited to one.
    if (id === null || id === undefined) continue;

    counts[id] = (counts[id] ?? 0) + 1;
  }

  return counts;
}

/**
 * Does this site have a database? `null` means "we cannot tell".
 *
 * The three answers are deliberately distinct. Collapsing unknown into "no"
 * would put a confident "this site has no database" under a site whose
 * databases merely sat on a page we never loaded — a wrong warning on a backup
 * screen is worse than no warning, because it argues someone out of a setting
 * they actually need.
 */
export function hasNoDatabase(counts, known, applicationId) {
  if (!known || applicationId === null || applicationId === undefined) return null;

  return (counts?.[applicationId] ?? 0) === 0;
}
