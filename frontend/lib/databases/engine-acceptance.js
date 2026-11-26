import { acceptedEngines } from "../applications/database-readiness.js";

export function engineAccepted({ application, siteTypes, engine } = {}) {
  if (!engine) return true;

  const type = (Array.isArray(siteTypes) ? siteTypes : []).find(
    (candidate) => candidate?.name === application?.site_type,
  );

  if (!type) return true;
  if (!type.needs_database) return true;

  const accepted = acceptedEngines(type);
  if (accepted === null) return true;

  return accepted.includes(engine);
}

export function acceptedEnginesFor({ application, siteTypes } = {}) {
  const type = (Array.isArray(siteTypes) ? siteTypes : []).find(
    (candidate) => candidate?.name === application?.site_type,
  );
  if (!type?.needs_database) return [];
  return acceptedEngines(type) ?? [];
}
