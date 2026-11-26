import { api } from "@/lib/api/client";

export function getFirewall({ signal } = {}) {
  return api.get("/firewall", { signal });
}

export function createFirewallRule(payload) {
  return api.post("/firewall/rules", payload);
}

export function updateFirewallRule(id, payload) {
  return api.put(`/firewall/rules/${encodeURIComponent(id)}`, payload);
}

export function deleteFirewallRule(id) {
  return api.delete(`/firewall/rules/${encodeURIComponent(id)}`);
}

// The backend adds SSH and panel allow rules first, so enabling can't lock you out.
export function toggleFirewall(enabled) {
  return api.put("/firewall/toggle", { enabled });
}

export function adoptUnmanagedRule(key) {
  return api.post("/firewall/unmanaged/adopt", { key });
}

export function deleteUnmanagedRule(key) {
  return api.delete("/firewall/unmanaged", { data: { key } });
}
