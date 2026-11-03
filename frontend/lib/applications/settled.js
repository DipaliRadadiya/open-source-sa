// A redeploy sets status back to `provisioning`; `last_deployed_at` survives it.
export function hasBeenDeployed(application) {
  return Boolean(application?.last_deployed_at || application?.code_on_disk?.commit);
}

// Not judged on `status` alone, which flips during every redeploy.
export function isSettled(application) {
  return application?.status === "active" || hasBeenDeployed(application);
}

// A Git site's first deploy is queued while the site already reads `active` with no code and no
// process yet (~10 s), which read "Stopped". A failed first deploy leaves `failed`.
export function firstDeployPending(application) {
  return (
    application?.status === "active" &&
    Boolean(application?.repository || application?.repository_url) &&
    !hasBeenDeployed(application)
  );
}

/** A live site running a new deploy, rather than one being set up; or a first deploy still queued. */
export function isRedeploying(application) {
  return (application?.status === "provisioning" && hasBeenDeployed(application)) || firstDeployPending(application);
}
