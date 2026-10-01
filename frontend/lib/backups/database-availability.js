// A "files + database" backup of a site without one succeeds silently with no database.
// `/databases` has no application filter, so the full list is grouped here.
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

// `null` means unknown; never collapse it into "no": a wrong warning could argue
// someone out of a setting they need.
export function hasNoDatabase(counts, known, applicationId) {
  if (!known || applicationId === null || applicationId === undefined) return null;

  return (counts?.[applicationId] ?? 0) === 0;
}

// Only site types declaring `needs_database` are flagged, so static sites carry no
// permanent warning. Unknown types answer false.
export function siteNeedsDatabase(siteTypes = [], siteType) {
  if (!siteType) return false;

  return Boolean(
    (siteTypes ?? []).find((type) => type.name === siteType)?.needs_database,
  );
}

/** Empty when counts are unknown: an unread list must never read as "none have a database". */
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

/** Compares ids as strings: the API sends numbers, forms send strings. */
export function applicationById(applications = [], applicationId) {
  if (applicationId === null || applicationId === undefined) return null;

  return (applications ?? []).find(
    (application) => String(application.id) === String(applicationId),
  ) ?? null;
}

// One database per site, though `POST /databases` allows more: staging and cloning
// only pick up the first.
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
