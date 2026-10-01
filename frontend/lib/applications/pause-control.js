/**
 * Which pause control a site's menu should offer, if either.
 *
 * `is_disabled` is checked first: pausing leaves `status` "active", so a paused
 * site would otherwise be offered a second Pause (API 422). Pause is offered
 * only on a served site; Resume whenever the site is paused, regardless of status.
 */
export function pauseControl(application, { canManage = false } = {}) {
  if (!canManage || !application) return null;
  if (application.is_disabled) return "resume";
  return application.status === "active" ? "pause" : null;
}
