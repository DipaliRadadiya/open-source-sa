// Ranges count: `3300-3400` contains MySQL. Only `allow` with no source qualifies.
export function riskyExposure({ port, portTo, action = "allow", source, riskyPorts = [] }) {
  if (action !== "allow") return null;
  if (source && String(source).trim()) return null;
  if (!riskyPorts.length) return null;

  const a = Number(port);
  if (!Number.isFinite(a)) return null;
  // `null` is no range: Number(null) is 0, which turned a single port into 0–port and
  // flagged SMTP and Docker for anything above 2375.
  const b = portTo != null && portTo !== "" && Number.isFinite(Number(portTo)) ? Number(portTo) : a;
  const low = Math.min(a, b);
  const high = Math.max(a, b);

  const hit = riskyPorts
    .filter((entry) => entry.port >= low && entry.port <= high)
    .map((entry) => entry.label);

  return hit.length ? hit.join(", ") : null;
}
