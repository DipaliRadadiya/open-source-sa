import { api } from "@/lib/api/client";

export function getFail2ban({ signal } = {}) {
  return api.get("/fail2ban", { signal });
}

// 202, queued: poll `GET /fail2ban` until `installed` flips. Enables no jails.
export function installFail2ban() {
  return api.post("/fail2ban/install");
}

// One file rewritten whole; omitted jails keep their state.
// `acknowledged` is needed to enable a lockout-risk jail without the caller's IP on the ignore list.
export function updateFail2ban(payload) {
  return api.put("/fail2ban", payload);
}

// One entry against the list on disk, so two people editing it no longer
// overwrite each other (36f9840a). Ban rules and jails are left alone.
export function addIgnoreIp(ip) {
  return api.post("/fail2ban/ignore-ips", { ip });
}

// In the query: a range's slash cannot go in a path. `acknowledged` passes the
// guard against removing the entry that keeps the SSH jail off you.
export function removeIgnoreIp(ip, { acknowledged = false } = {}) {
  return api.delete("/fail2ban/ignore-ips", {
    params: { ip, ...(acknowledged ? { acknowledged: 1 } : null) },
  });
}

export function banIp(ip, jail) {
  return api.post("/fail2ban/bans", { ip, jail });
}

/** Release one address — from every jail holding it unless `jail` is given. */
export function unbanIp(ip, jail) {
  return api.delete(`/fail2ban/bans/${encodeURIComponent(ip)}`, {
    params: jail ? { jail } : undefined,
  });
}

/** Release every ban (e.g. an office or VPN range banned by mistake). */
export function unbanAll() {
  return api.delete("/fail2ban/bans");
}
