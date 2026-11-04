/**
 * What a container site is doing, in the words the badge uses:
 * `starting` | `running` | `degraded` | `restarting` | `failed`, or null when there is
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
  // `unhealthy`: the image's own HEALTHCHECK fails (DS-14).
  if (application.last_failure || checked === "exited" || checked === "not_answering" || checked === "unhealthy") return "failed";
  if (application.status === "failed") return "failed";
  // The main part answers but a helper (e.g. Supabase's Edge Functions) does not: yellow, not green.
  if (checked === "running") return healthWarnings(application).length ? "degraded" : "running";
  // The image's HEALTHCHECK has no verdict yet (DS-14).
  if (checked === "starting") return "starting";
  return null;
}

/** The API's `health_warnings`, always an array. */
export function healthWarnings(application) {
  return Array.isArray(application?.health_warnings) ? application.health_warnings : [];
}

/**
 * "functions (unhealthy), kong (stopped)": service names as they are, states worded through
 * `helperState.*`, an unknown future code shown raw. `t` is the `applications` translator.
 */
export function describeWarnings(warnings, t) {
  return warnings
    .map(({ service, state }) => `${service} (${t.has(`helperState.${state}`) ? t(`helperState.${state}`) : state})`)
    .join(", ");
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
