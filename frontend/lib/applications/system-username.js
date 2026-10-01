// Mirrors the backend's SystemUsernameGenerator and
// StoreSystemUserRequest::RESERVED; keep in step.
const RESERVED = new Set([
  "root", "daemon", "bin", "sys", "sync", "games", "man", "lp", "mail",
  "news", "uucp", "proxy", "www-data", "backup", "list", "irc", "gnats",
  "nobody", "systemd-network", "mysql", "postgres", "mongodb", "redis",
  "ubuntu", "admin", "sshd",
]);
const MAX = 32;

function base(name) {
  const slug = String(name ?? "")
    .normalize("NFKD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^[-0-9]+/, "")
    .slice(0, MAX)
    .replace(/-+$/, "");
  if (!slug) return "";
  return RESERVED.has(slug) ? `app-${slug}`.slice(0, MAX) : slug;
}

/**
 * A system user name for an application called `name`, avoiding `taken`.
 * Empty when the name has nothing usable in it; see fallbackSystemUsername.
 */
export function suggestSystemUsername(name, taken = []) {
  const first = base(name);
  if (!first) return "";
  const used = new Set(taken);
  if (!used.has(first)) return first;
  // A counter, not the backend's random suffix: a random value would cause a
  // hydration mismatch.
  for (let n = 2; ; n += 1) {
    const suffix = `-${n}`;
    const candidate = `${first.slice(0, MAX - suffix.length).replace(/-+$/, "")}${suffix}`;
    if (!used.has(candidate)) return candidate;
  }
}

/**
 * The backend's fallback name: `app-` plus four random characters.
 * Call after mount only, to avoid a hydration mismatch.
 */
export function fallbackSystemUsername(taken = []) {
  const used = new Set(taken);
  const chars = "abcdefghijklmnopqrstuvwxyz0123456789";
  let candidate;
  do {
    const bytes = crypto.getRandomValues(new Uint8Array(4));
    candidate = `app-${[...bytes].map((n) => chars[n % chars.length]).join("")}`;
  } while (used.has(candidate));
  return candidate;
}
