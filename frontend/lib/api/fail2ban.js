import { api } from "@/lib/api/client";

export function getFail2ban({ signal } = {}) {
  return api.get("/fail2ban", { signal });
}

/**
 * Queued — apt is slow, so this returns 202 and the caller polls `GET /fail2ban`
 * until `installed` flips. Nothing is enabled by the install: a fail2ban that
 * started banning the moment it appeared would be a nasty surprise.
 */
export function installFail2ban() {
  return api.post("/fail2ban/install");
}

/**
 * Settings, ignore list and jail toggles in one call, because they all live in
 * one file that is rewritten whole. A jail you omit keeps its current state, so
 * a single toggle can be sent on its own without touching the others.
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

/**
 * Release every ban. This exists for one moment — an office or VPN range banned
 * by mistake — where unbanning rows one at a time is not what's called for.
 */
export function unbanAll() {
  return api.delete("/fail2ban/bans");
}
