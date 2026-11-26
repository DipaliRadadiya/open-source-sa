// status alone isn't enough: a failed container update leaves the site active with last_failure set.
export function containerState(application) {
  if (application?.serving_profile !== "docker") return null;
  if (application.status === "pending" || application.status === "provisioning") return "starting";
  const checked = application.container_status;
  if (checked === "restarting") return "restarting";
  if (application.last_failure || checked === "exited" || checked === "not_answering" || checked === "unhealthy") return "failed";
  if (application.status === "failed") return "failed";
  if (checked === "running") return healthWarnings(application).length ? "degraded" : "running";
  if (checked === "starting") return "starting";
  return null;
}

export function healthWarnings(application) {
  return Array.isArray(application?.health_warnings) ? application.health_warnings : [];
}

export function describeWarnings(warnings, t) {
  return warnings
    .map(({ service, state }) => `${service} (${t.has(`helperState.${state}`) ? t(`helperState.${state}`) : state})`)
    .join(", ");
}

export function hasContainerFailure(application) {
  const state = containerState(application);
  return (state === "failed" || state === "restarting") && Boolean(application.last_failure);
}

export function portFixFor(application, detectedPort) {
  if (application?.last_failure?.reason !== "container_port_mismatch") return null;
  const port = Number(detectedPort);
  if (!Number.isInteger(port) || port < 1 || port > 65535) return null;
  return port === Number(application.container_port) ? null : port;
}
