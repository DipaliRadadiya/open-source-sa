import { api } from "@/lib/api/client";
import { RETRY_RESTART } from "@/lib/api/restart-blip";

export function getFail2ban({ signal } = {}) {
  return api.get("/fail2ban", { signal });
}

export function installFail2ban() {
  return api.post("/fail2ban/install", undefined, RETRY_RESTART);
}

export function updateFail2ban(payload) {
  return api.put("/fail2ban", payload);
}

export function addIgnoreIp(ip) {
  return api.post("/fail2ban/ignore-ips", { ip });
}

// Sent as a query param because a CIDR slash can't go in the path.
export function removeIgnoreIp(ip, { acknowledged = false } = {}) {
  return api.delete("/fail2ban/ignore-ips", {
    params: { ip, ...(acknowledged ? { acknowledged: 1 } : null) },
  });
}

export function banIp(ip, jail) {
  return api.post("/fail2ban/bans", { ip, jail });
}

export function unbanIp(ip, jail) {
  return api.delete(`/fail2ban/bans/${encodeURIComponent(ip)}`, {
    params: jail ? { jail } : undefined,
  });
}

export function unbanAll() {
  return api.delete("/fail2ban/bans");
}
