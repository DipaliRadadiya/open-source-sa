/**
 * What a container site is doing, in the four words the badge uses:
 * `starting` | `running` | `restarting` | `failed`, or null when there is
 * nothing container-specific to say (not a container, one-click apps, sites
 * deployed before the readiness check existed).
 *
 * `status` alone cannot say it: a PUT /container or a Pull that fails leaves
 * the site `active` with `last_failure` set, and that site serves a 502.
 */
export function containerState(application) {
  if (application?.serving_profile !== "docker") return null;
  if (application.status === "pending" || application.status === "provisioning") return "starting";
  const checked = application.container_status;
  if (checked === "restarting") return "restarting";
  if (application.last_failure || checked === "exited" || checked === "not_answering") return "failed";
  if (application.status === "failed") return "failed";
  if (checked === "running") return "running";
  return null;
}

/** A container that is down right now, with a stored reason to show. */
export function hasContainerFailure(application) {
  const state = containerState(application);
  return (state === "failed" || state === "restarting") && Boolean(application.last_failure);
}

/**
 * The port to offer as the one-click fix: only for a port mismatch, and only
 * when the image's own port is known and differs from the one configured.
 */
export function portFixFor(application, detectedPort) {
  if (application?.last_failure?.reason !== "container_port_mismatch") return null;
  const port = Number(detectedPort);
  if (!Number.isInteger(port) || port < 1 || port > 65535) return null;
  return port === Number(application.container_port) ? null : port;
}
