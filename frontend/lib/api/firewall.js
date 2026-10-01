import { api } from "@/lib/api/client";

export function getFirewall({ signal } = {}) {
  return api.get("/firewall", { signal });
}

export function createFirewallRule(payload) {
  return api.post("/firewall/rules", payload);
}

/**
 * Edit a rule, or switch it off with `enabled: false` (kept, but removed from
 * UFW). This is how the old SSH port is closed; system-seeded rules cannot be
 * deleted.
 */
export function updateFirewallRule(id, payload) {
  return api.put(`/firewall/rules/${encodeURIComponent(id)}`, payload);
}

export function deleteFirewallRule(id) {
  return api.delete(`/firewall/rules/${encodeURIComponent(id)}`);
}

/**
 * Enabling seeds allow-rules for SSH and the panel's ports before defaulting to
 * deny, so it cannot lock the caller out. Disabling keeps every rule.
 */
export function toggleFirewall(enabled) {
  return api.put("/firewall/toggle", { enabled });
}
