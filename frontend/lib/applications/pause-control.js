/**
 * Which of the two pause controls a site's menu should offer, if either.
 *
 * It lives here rather than inline in the menu because it is the whole feature:
 * one of these controls is a confirmed, reversible outage and the other is the
 * way back from it, and offering the wrong one is worse than offering neither.
 *
 * `is_disabled` is asked first on purpose. Pausing leaves `status` alone — the
 * backend swaps the vhost and stamps `disabled_at`, and never touches the
 * provisioning state — so a paused site still reads as "active" and would
 * otherwise be offered a second Pause that the API answers 422 to.
 *
 * Pausing is offered only on a site that is actually being served. There is
 * nothing to turn visitors away from while it is still provisioning or has
 * failed, and the API's vhost swap has nothing to swap.
 *
 * Resuming is offered whenever the site is paused, with no status condition:
 * a site that got into that state is entitled to get out of it regardless of
 * what else is true about it.
 */
export function pauseControl(application, { canManage = false } = {}) {
  if (!canManage || !application) return null;
  if (application.is_disabled) return "resume";
  return application.status === "active" ? "pause" : null;
}
