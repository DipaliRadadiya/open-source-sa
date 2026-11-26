export function hasBeenDeployed(application) {
  return Boolean(application?.last_deployed_at || application?.code_on_disk?.commit);
}

export function isSettled(application) {
  return application?.status === "active" || hasBeenDeployed(application);
}

export function firstDeployPending(application) {
  return (
    application?.status === "active" &&
    Boolean(application?.repository || application?.repository_url) &&
    !hasBeenDeployed(application)
  );
}

export function containerRedeployInFlight(application) {
  return Boolean(application?.container_redeploy?.in_flight);
}

export function containerRedeployFailure(run, pullFailedText) {
  return run?.failed_reason_title ?? (run?.failed_step === "image_pull" ? pullFailedText : null);
}

export function isRedeploying(application) {
  return (
    (application?.status === "provisioning" && hasBeenDeployed(application)) ||
    firstDeployPending(application) ||
    containerRedeployInFlight(application)
  );
}
