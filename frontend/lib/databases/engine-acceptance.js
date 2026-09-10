import { acceptedEngines } from "../applications/database-readiness.js";

/**
 * Whether a site's application can speak the engine a database runs on.
 *
 * `UpdateDatabaseApplicationRequest::refuseUnusableEngine()` rejects the pair —
 * a MongoDB database attached to WordPress, say — but the panel offered every
 * site in the picker and let the refusal arrive after the choice. Same source
 * of truth as the create form's type grid: the catalogue's `accepted_engines`.
 *
 * A type that needs no database accepts anything. That is the backend's rule
 * verbatim: for a custom or static site the link is bookkeeping, and the panel
 * does not know better than the user what their own code connects to.
 */
export function engineAccepted({ application, siteTypes, engine } = {}) {
  if (!engine) return true;

  const type = (Array.isArray(siteTypes) ? siteTypes : []).find(
    (candidate) => candidate?.name === application?.site_type,
  );

  // A site type we cannot find is one we cannot rule on. Blocking on a
  // catalogue that failed to load would empty the picker over a fetch.
  if (!type) return true;
  if (!type.needs_database) return true;

  const accepted = acceptedEngines(type);
  // Null means the catalogue named no engines, which is an answer: there is
  // nothing to hold the pairing to.
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
