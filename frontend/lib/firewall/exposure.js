/**
 * Which ports should not face the open internet. The list comes from the API's
 * `risky_ports[]` (engines installed on this server); only the matching logic
 * lives here.
 */

/**
 * The service name(s) a wide-open allow rule would expose, or null if none.
 *
 * Ranges count: `3300-3400` contains MySQL and is more exposure, not less.
 * Only `allow` with no source qualifies; a source-restricted rule is the fix, and
 * `deny` is the opposite of the problem.
 */
export function riskyExposure({ port, portTo, action = "allow", source, riskyPorts = [] }) {
  if (action !== "allow") return null;
  if (source && String(source).trim()) return null;
  if (!riskyPorts.length) return null;

  const a = Number(port);
  if (!Number.isFinite(a)) return null;
  const b = Number.isFinite(Number(portTo)) ? Number(portTo) : a;
  const low = Math.min(a, b);
  const high = Math.max(a, b);

  const hit = riskyPorts
    .filter((entry) => entry.port >= low && entry.port <= high)
    .map((entry) => entry.label);

  return hit.length ? hit.join(", ") : null;
}
