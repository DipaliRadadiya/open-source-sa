import { acceptedEngines } from "../applications/database-readiness.js";

/**
 * Whether a site's application can use a database's engine. Mirrors
 * `UpdateDatabaseApplicationRequest::refuseUnusableEngine()`, using the
 * catalogue's `accepted_engines`. Types that need no database accept anything,
 * as on the backend.
 */
export function engineAccepted({ application, siteTypes, engine } = {}) {
  if (!engine) return true;

  const type = (Array.isArray(siteTypes) ? siteTypes : []).find(
    (candidate) => candidate?.name === application?.site_type,
  );

  // Unknown type (e.g. the catalogue failed to load): do not block.
  if (!type) return true;
  if (!type.needs_database) return true;

  const accepted = acceptedEngines(type);
  // Null: the catalogue names no engines, so nothing to check against.
  if (accepted === null) return true;

  return accepted.includes(engine);
}

/**
 * The engines a site's type accepts, for the sentence that says why not.
 * Empty when there is nothing to say.
 */
export function acceptedEnginesFor({ application, siteTypes } = {}) {
  const type = (Array.isArray(siteTypes) ? siteTypes : []).find(
    (candidate) => candidate?.name === application?.site_type,
  );
  if (!type?.needs_database) return [];
  return acceptedEngines(type) ?? [];
}
