/**
 * Which ports should not face the open internet — now answered by the server.
 *
 * This file used to carry its own map of database ports. The API sends
 * `risky_ports[]` derived from the engines actually installed on this machine,
 * so the warning is about THIS server rather than about servers in general, and
 * a list in the UI can no longer drift from what's really running. Only the
 * matching logic stays here.
 */

/**
 * The service name(s) a wide-open allow rule would expose, or null if none.
 *
 * Ranges count. `3300-3400` contains MySQL, and checking only the first port of
 * a range meant the widest, most dangerous rules were the ones that escaped the
 * warning — a range is more exposure than a single port, not less.
 *
 * Only `allow` + no source qualifies: restricted to an address is exactly what
 * you should be doing, and a `deny` rule is the opposite of the problem.
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
