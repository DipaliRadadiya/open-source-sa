/**
 * What the firewall card is looking at. Three states, not two:
 *
 * off       not running. The rules below are saved but inert.
 * on        running, and anything not listed is blocked.
 * exposed   running, and anything not listed is let straight in.
 *
 * The panel cannot cause `exposed` (ToggleFirewall::execute(true) runs
 * `ufw default deny incoming` before enabling); it takes someone running
 * `ufw default allow incoming` over SSH, and nothing else would surface it.
 *
 * Only an explicit allow counts: `default_policy.incoming` is a free-form string
 * and an unrecognised value is not evidence. Compared case-insensitively so the
 * signal does not depend on backend casing.
 */
export function firewallState(enabled, policy) {
  // Unread is not off: "off" offers "Turn on", and a firewall the panel cannot
  // read needs fixing on the server first.
  if (enabled === null || enabled === undefined) return "unknown";
  if (!enabled) return "off";

  const incoming = typeof policy?.incoming === "string" ? policy.incoming.trim().toLowerCase() : null;

  return incoming === "allow" ? "exposed" : "on";
}

// What deleting a rule changes now: removing a Block rule lets traffic in, and a
// disabled rule is not enforced at all.
export function deleteRuleBodyKey(firewallOn, rule) {
  if (!firewallOn) return "rules.confirmBodyOff";
  if (rule?.enabled === false) return "rules.confirmBodyRuleOff";
  return rule?.action === "deny" ? "rules.confirmBodyOnDeny" : "rules.confirmBodyOn";
}
