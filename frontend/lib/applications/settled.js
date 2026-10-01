// A redeploy sets status back to `provisioning`; `last_deployed_at` survives it.
export function hasBeenDeployed(application) {
  return Boolean(application?.last_deployed_at || application?.code_on_disk?.commit);
}

// Not judged on `status` alone, which flips during every redeploy.
export function isSettled(application) {
  return application?.status === "active" || hasBeenDeployed(application);
}

/** A live site running a new deploy, rather than one being set up. */
export function isRedeploying(application) {
  return application?.status === "provisioning" && hasBeenDeployed(application);
}
