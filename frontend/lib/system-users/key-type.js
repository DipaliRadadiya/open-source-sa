// The key's algorithm as people say it; the wire names (ssh-ed25519, sk-…@openssh.com) are noise.
// Returns { algo, securityKey } or null for a type this panel does not know (older APIs send none).
const ALGOS = { "ssh-ed25519": "Ed25519", "ssh-rsa": "RSA", "ssh-dss": "DSA" };

export function keyType(type) {
  if (typeof type !== "string" || !type) return null;
  const securityKey = type.startsWith("sk-");
  const bare = type.replace(/^sk-/, "").replace(/@openssh\.com$/, "");
  const algo = ALGOS[bare] ?? (bare.startsWith("ecdsa-sha2-") ? "ECDSA" : null);
  return algo ? { algo, securityKey } : null;
}
