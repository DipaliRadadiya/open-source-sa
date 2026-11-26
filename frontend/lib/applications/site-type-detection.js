// Copy of config('server.site_type_detection.generic'), which the API doesn't expose.
export const GENERIC_SITE_TYPES = ["php", "static"];

export function isGitSite(application) {
  return String(application?.site_type) === "git";
}

export function canDetectSiteType(application) {
  return !isGitSite(application);
}

export function suggestedSiteType(application) {
  return application?.site_type_detection?.suggested ?? null;
}

export function siteTypeDetectionState(application) {
  if (isGitSite(application)) return "unsupported";
  if (suggestedSiteType(application)) return "suggested";
  if (!application?.site_type_detection?.checked_at) return "idle";

  return application.site_type_detection.detected ? "recognised" : "found";
}

export function narrowingTargets(application) {
  if (!canDetectSiteType(application)) return [];
  return GENERIC_SITE_TYPES.filter((type) => type !== String(application?.site_type));
}
