export function countByApplication(databases = []) {
  const counts = {};

  for (const database of databases ?? []) {
    const id = database?.application_id;
    if (id === null || id === undefined) continue;

    counts[id] = (counts[id] ?? 0) + 1;
  }

  return counts;
}

export function hasNoDatabase(counts, known, applicationId) {
  if (!known || applicationId === null || applicationId === undefined) return null;

  return (counts?.[applicationId] ?? 0) === 0;
}

export function siteNeedsDatabase(siteTypes = [], siteType) {
  if (!siteType) return false;

  return Boolean(
    (siteTypes ?? []).find((type) => type.name === siteType)?.needs_database,
  );
}

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

export function attachedApplication(database, applications = []) {
  if (database?.application_id === null || database?.application_id === undefined) return null;
  const known = applicationById(applications, database.application_id);
  return { id: database.application_id, name: known?.name ?? database.application_name ?? null };
}

export function applicationById(applications = [], applicationId) {
  if (applicationId === null || applicationId === undefined) return null;

  return (applications ?? []).find(
    (application) => String(application.id) === String(applicationId),
  ) ?? null;
}

export function applicationOptions(
  applications = [],
  counts = null,
  known = false,
  reason = "",
  engineReason = null,
) {
  return (applications ?? []).map((application) => {
    const taken = known && (counts?.[application.id] ?? 0) > 0 ? reason : undefined;

    return {
      value: String(application.id),
      label: application.name,
      hint: application.domain ?? undefined,
      disabledReason: taken ?? engineReason?.(application) ?? undefined,
    };
  });
}
