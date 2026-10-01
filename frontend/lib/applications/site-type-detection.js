// Display logic only: the backend re-checks everything at apply time.

// Mirrors `config('server.site_type_detection.generic')`, which the API does not expose.
// Replace with the API flag once it exists; if stale, the panel only under-offers relabels.
export const GENERIC_SITE_TYPES = ["php", "static"];

/** A git site's type can never change, in either direction. */
export function isGitSite(application) {
  return String(application?.site_type) === "git";
}

/** Whether type detection can run; the backend refuses to probe git sites. */
export function canDetectSiteType(application) {
  return !isGitSite(application);
}

// Never re-derive from `detected` + `confidence`: that duplicates the backend's gates.
export function suggestedSiteType(application) {
  return application?.site_type_detection?.suggested ?? null;
}

// `recognised` (labelled correctly) and `found` (nothing known) must stay distinct.
export function siteTypeDetectionState(application) {
  if (isGitSite(application)) return "unsupported";
  if (suggestedSiteType(application)) return "suggested";
  // Only `checked_at` proves a probe ran; `detected` is also null before one.
  if (!application?.site_type_detection?.checked_at) return "idle";

  return application.site_type_detection.detected ? "recognised" : "found";
}

// Narrowing only, excluding the current type (the API refuses `unchanged`).
export function narrowingTargets(application) {
  if (!canDetectSiteType(application)) return [];
  return GENERIC_SITE_TYPES.filter((type) => type !== String(application?.site_type));
}
