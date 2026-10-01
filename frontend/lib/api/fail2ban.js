import { api } from "@/lib/api/client";

export function getFail2ban({ signal } = {}) {
  return api.get("/fail2ban", { signal });
}

/**
 * Queued: returns 202 and the caller polls `GET /fail2ban` until `installed`
 * flips. The install enables no jails.
 */
export function installFail2ban() {
  return api.post("/fail2ban/install");
}

/**
 * Settings, ignore list and jail toggles in one call (one file rewritten
 * whole). Omitted jails keep their current state.
 *
 * `acknowledged` is only needed to enable a lockout-risk jail without the
 * caller's own IP on the ignore list.
 */
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
