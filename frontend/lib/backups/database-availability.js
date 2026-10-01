/**
 * Database count per site, for the backup form: a "files + database" backup of
 * a site without one succeeds silently with no database in it.
 *
 * `/databases` has no application filter, so this groups the full list by
 * `application_id`.
 */
export function countByApplication(databases = []) {
  const counts = {};

  for (const database of databases ?? []) {
    const id = database?.application_id;
    // Server-level databases belong to no site.
    if (id === null || id === undefined) continue;

    counts[id] = (counts[id] ?? 0) + 1;
  }

  return counts;
}

/**
 * Does this site have a database? `null` means unknown; never collapse it into
 * "no", since a wrong warning could argue someone out of a setting they need.
 */
export function hasNoDatabase(counts, known, applicationId) {
  if (!known || applicationId === null || applicationId === undefined) return null;

  return (counts?.[applicationId] ?? 0) === 0;
}

/**
 * Does this KIND of site need a database? Only types declaring `needs_database`
 * (WordPress, PrestaShop, NodeBB...) are flagged, so static or blank sites do
 * not carry a permanent warning. Unknown types answer false.
 * `needs_database` lives on the site type, not the application.
 */
export function siteNeedsDatabase(siteTypes = [], siteType) {
  if (!siteType) return false;

  return Boolean(
    (siteTypes ?? []).find((type) => type.name === siteType)?.needs_database,
  );
}

/**
 * Ids of sites that need a database and have none. Empty when counts are
 * unknown: an unread list must never render as "none have a database".
 */
export function sitesMissingDatabase(applications = [], siteTypes = [], counts = null, known = false) {
  if (!known) return new Set();

  return new Set(
    (applications ?? [])
      .filter(
        (application) =>
          siteNeedsDatabase(siteTypes, application.site_type)
          && (counts?.[application.id] ?? 0) === 0,
      )
      .map((application) => application.id),
  );
}

/**
 * The site a database belongs to, or null. Compares ids as strings: the API
 * sends numbers, forms send strings.
 */
export function applicationById(applications = [], applicationId) {
  if (applicationId === null || applicationId === undefined) return null;

  return (applications ?? []).find(
    (application) => String(application.id) === String(applicationId),
  ) ?? null;
}

/**
 * Site options for a "which site is this database for?" picker.
 *
 * A site that already has a database is shown but disabled with the reason.
 * One per site is enforced even though `POST /databases` allows more, because
 * staging and cloning only pick up the first.
 */
export function applicationOptions(
  applications = [],
  counts = null,
  known = false,
  reason = "",
  // A function because the create dialog's engine can change while it is open.
  engineReason = null,
) {
  return (applications ?? []).map((application) => {
    // "Already has one" wins when both reasons apply: it is more actionable.
    const taken = known && (counts?.[application.id] ?? 0) > 0 ? reason : undefined;

    return {
      value: String(application.id),
      label: application.name,
      hint: application.domain ?? undefined,
      // Unknown counts block nothing; the API may still refuse a second attach.
      disabledReason: taken ?? engineReason?.(application) ?? undefined,
    };
  });
}
