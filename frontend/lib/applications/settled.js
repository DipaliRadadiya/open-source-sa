/**
 * Whether an application has ever had code deployed to it.
 *
 * A redeploy puts a live site back into `provisioning` (and `deployed` false)
 * for its duration, while the old code keeps serving. `last_deployed_at` is
 * written on success only and survives every later deploy, so it tells a
 * first build from a site that is already live.
 */
export function hasBeenDeployed(application) {
  return Boolean(application?.last_deployed_at || application?.code_on_disk?.commit);
}

/**
 * Whether the pages about a live site (files, domains, environment…) have
 * anything to show. Judged on `status` alone, every redeploy swapped them all
 * for "still being set up" mid-flight.
 */
export function isSettled(application) {
  return application?.status === "active" || hasBeenDeployed(application);
}

/** A live site running a new deploy, rather than one being set up. */
export function isRedeploying(application) {
  return application?.status === "provisioning" && hasBeenDeployed(application);
}
