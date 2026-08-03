import { api } from "@/lib/api/client";

export function getFirewall({ signal } = {}) {
  return api.get("/firewall", { signal });
}

export function createFirewallRule(payload) {
  return api.post("/firewall/rules", payload);
}

/**
 * Edit a rule, or switch it off with `enabled: false`.
 *
 * Disabling keeps the rule and removes it from UFW, which is how the old SSH
 * port gets closed after the port moves: deleting it is refused (it is
 * system-seeded), and deleting is the wrong verb anyway — the user may want it
 * back if the new port turns out to be unreachable.
 */
export function updateFirewallRule(id, payload) {
  return api.put(`/firewall/rules/${encodeURIComponent(id)}`, payload);
}

export function deleteFirewallRule(id) {
  return api.delete(`/firewall/rules/${encodeURIComponent(id)}`);
}

/**
 * Enabling seeds allow-rules for SSH and the panel's ports *before* switching the
 * default policy to deny, so turning the firewall on cannot cut off the person
 * turning it on. Disabling keeps every rule — re-enabling restores them.
 */
export function toggleFirewall(enabled) {
  return api.put("/firewall/toggle", { enabled });
}
