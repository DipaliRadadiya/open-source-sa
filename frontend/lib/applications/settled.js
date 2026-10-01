/**
 * Whether an application has ever had code deployed. A redeploy sets status
 * back to `provisioning` while old code keeps serving; `last_deployed_at`
 * survives it, so it tells a first build from a live site.
 */
export function hasBeenDeployed(application) {
  return Boolean(application?.last_deployed_at || application?.code_on_disk?.commit);
}

/**
 * Whether the live-site pages (files, domains, environment…) have anything to
 * show. Not judged on `status` alone, which flips during every redeploy.
 */
export function isSettled(application) {
  return application?.status === "active" || hasBeenDeployed(application);
}

/** A live site running a new deploy, rather than one being set up. */
export function isRedeploying(application) {
  return application?.status === "provisioning" && hasBeenDeployed(application);
}
