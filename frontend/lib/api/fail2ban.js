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
