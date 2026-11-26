const ALGOS = { "ssh-ed25519": "Ed25519", "ssh-rsa": "RSA", "ssh-dss": "DSA" };

export function keyType(type) {
  if (typeof type !== "string" || !type) return null;
  const securityKey = type.startsWith("sk-");
  const bare = type.replace(/^sk-/, "").replace(/@openssh\.com$/, "");
  const algo = ALGOS[bare] ?? (bare.startsWith("ecdsa-sha2-") ? "ECDSA" : null);
  return algo ? { algo, securityKey } : null;
}
