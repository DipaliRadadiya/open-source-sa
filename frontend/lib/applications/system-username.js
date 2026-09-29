// Mirrors SystemUsernameGenerator + StoreSystemUserRequest::RESERVED on the
// backend, so the name the form suggests is the one the API would have picked.
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
 * Empty while the name has nothing usable in it, so the field waits for one
 * instead of filling with a random `app-xxxx`.
 */
export function suggestSystemUsername(name, taken = []) {
  const first = base(name);
  if (!first) return "";
  const used = new Set(taken);
  if (!used.has(first)) return first;
  // A counter, not the backend's random suffix: this also renders on the
  // server, and a random default would differ once the browser hydrates.
  for (let n = 2; ; n += 1) {
    const suffix = `-${n}`;
    const candidate = `${first.slice(0, MAX - suffix.length).replace(/-+$/, "")}${suffix}`;
    if (!used.has(candidate)) return candidate;
  }
}
