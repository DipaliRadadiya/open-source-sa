/**
 * What the panel may offer to do about a site's type.
 *
 * The backend owns the rules; this only decides what to put on screen. Every
 * refusal is re-made server-side at apply time, so nothing here is a
 * permission check — it is the difference between offering a control that can
 * work and offering one that can only fail.
 */

/*
 * Mirrors `config('server.site_type_detection.generic')`.
 *
 * ⚠️ DUPLICATED, and it should not be: the site-type catalog
 * (`GET /site-types`) already carries `needs_database`, `accepted_engines`,
 * `available` and `unavailable_reason` per type, but not `generic` or
 * `suggestable` — so there is no way to learn this from the API. Filed as a
 * backend ask; when the catalog carries a flag, delete this and read it.
 *
 * Chosen as the safe direction to be wrong in: if the backend adds a third
 * generic type, this list going stale means the panel stops OFFERING a relabel
 * it would allow. The reverse — inferring "generic" from something like
 * `needs_database` — would offer relabels the API then refuses, which is the
 * failure that wastes someone's afternoon.
 */
export const GENERIC_SITE_TYPES = ["php", "static"];

/** A git site's type can never change, in either direction. */
export function isGitSite(application) {
  return String(application?.site_type) === "git";
}

/**
 * Whether reading the disk could tell this site anything actionable.
 *
 * Git sites are excluded because the backend refuses to probe them at all —
 * their Deployments, Workers and .env screens come from being git-deployed,
 * and relabelling would hide those screens without stopping the workers behind
 * them. A Detect button that can only ever refuse is worse than no button.
 */
export function canDetectSiteType(application) {
  return !isGitSite(application);
}

/**
 * Whether to volunteer a relabel, unprompted.
 *
 * `suggested` is the backend's own answer and it is pre-validated against
 * every refusal the apply endpoint would make — so if it is set, accepting it
 * cannot come back a 422. Never re-derive it from `detected` + `confidence`:
 * that would reimplement four gates (git, unchanged, min-confidence, generic →
 * suggestable) and they would drift.
 */
export function suggestedSiteType(application) {
  return application?.site_type_detection?.suggested ?? null;
}

/**
 * Which of the four things the Type row has to say.
 *
 *   unsupported — git: no probe, no offer, no button
 *   idle        — never probed; offer to look
 *   suggested   — found something this site could become
 *   found       — probed and there is nothing to offer
 *
 * `found` splits into "recognised something, but nothing to offer" and "saw
 * nothing at all" at the call site, because only the second needs the API's
 * explanatory sentence. Softaculous's support board is full of "scan says no
 * installations found" — a probe that reports nothing and does not say so
 * reads as a broken button.
 */
export function siteTypeDetectionState(application) {
  if (isGitSite(application)) return "unsupported";
  if (suggestedSiteType(application)) return "suggested";
  // `checked_at` is the only field that proves a probe RAN. `detected` is null
  // both before the first probe and after one that found nothing, so branching
  // on it would show "nothing found" to someone who has never pressed the
  // button.
  if (application?.site_type_detection?.checked_at) return "found";
  return "idle";
}

/**
 * The types this site may be relabelled TO, for the manual escape hatch.
 *
 * Narrowing only, and never to its own current type. Widening is not offered
 * here at all: it needs evidence on disk, which is what the suggestion path is
 * for. `unchanged` is a refusal, so the current type is filtered out rather
 * than shown as a no-op.
 */
export function narrowingTargets(application) {
  if (!canDetectSiteType(application)) return [];
  return GENERIC_SITE_TYPES.filter((type) => type !== String(application?.site_type));
}
