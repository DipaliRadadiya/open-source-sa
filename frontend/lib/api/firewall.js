import { api } from "@/lib/api/client";

export function getFirewall({ signal } = {}) {
  return api.get("/firewall", { signal });
}

export function createFirewallRule(payload) {
  return api.post("/firewall/rules", payload);
}

// `enabled: false` keeps the rule but removes it from UFW; system-seeded rules cannot be deleted.
export function updateFirewallRule(id, payload) {
  return api.put(`/firewall/rules/${encodeURIComponent(id)}`, payload);
}

export function deleteFirewallRule(id) {
  return api.delete(`/firewall/rules/${encodeURIComponent(id)}`);
}

// Enabling seeds SSH and panel allow-rules before default deny, so it cannot lock the caller out.
export function toggleFirewall(enabled) {
  return api.put("/firewall/toggle", { enabled });
}
