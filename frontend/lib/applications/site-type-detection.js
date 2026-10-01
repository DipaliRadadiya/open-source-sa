/**
 * What the panel may offer to do about a site's type. Display logic only: the
 * backend re-checks everything at apply time.
 */

/*
 * Mirrors `config('server.site_type_detection.generic')`; `GET /site-types`
 * does not expose it. Replace with the API flag once it exists. If stale, the
 * panel only under-offers relabels, which is the safe direction.
 */
export const GENERIC_SITE_TYPES = ["php", "static"];

/** A git site's type can never change, in either direction. */
export function isGitSite(application) {
  return String(application?.site_type) === "git";
}

/** Whether type detection can run; the backend refuses to probe git sites. */
export function canDetectSiteType(application) {
  return !isGitSite(application);
}

/**
 * The backend's pre-validated relabel suggestion, or null. Never re-derive it
 * from `detected` + `confidence`: that would duplicate the backend's gates.
 */
export function suggestedSiteType(application) {
  return application?.site_type_detection?.suggested ?? null;
}

/**
 * Which of five states the Type row shows:
 *
 *   unsupported — git: no probe, no offer, no button
 *   idle        — never probed; offer to look
 *   suggested   — found something this site could become
 *   recognised  — probed, recognised software, nothing to offer
 *   found       — probed and the directory held nothing it knows
 *
 * `recognised` and `found` must stay distinct: a correctly labelled site is
 * recognised, not "nothing found".
 */
export function siteTypeDetectionState(application) {
  if (isGitSite(application)) return "unsupported";
  if (suggestedSiteType(application)) return "suggested";
  // Only `checked_at` proves a probe ran; `detected` is also null before one.
  if (!application?.site_type_detection?.checked_at) return "idle";

  return application.site_type_detection.detected ? "recognised" : "found";
}

/**
 * Types this site may be manually relabelled to: narrowing only, excluding
 * its current type (the API refuses `unchanged`). Widening goes through the
 * suggestion path.
 */
export function narrowingTargets(application) {
  if (!canDetectSiteType(application)) return [];
  return GENERIC_SITE_TYPES.filter((type) => type !== String(application?.site_type));
}
