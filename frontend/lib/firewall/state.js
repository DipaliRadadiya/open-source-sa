// `exposed`: running but letting unlisted traffic in (`ufw default allow incoming` over SSH).
// Only an explicit allow counts; an unrecognised policy string is not evidence.
export function firewallState(enabled, policy) {
  // Unread is not off: a firewall the panel cannot read needs fixing first.
  if (enabled === null || enabled === undefined) return "unknown";
  if (!enabled) return "off";

  const incoming = typeof policy?.incoming === "string" ? policy.incoming.trim().toLowerCase() : null;

  return incoming === "allow" ? "exposed" : "on";
}

// Removing a Block rule lets traffic in; a disabled rule is not enforced at all.
export function deleteRuleBodyKey(firewallOn, rule) {
  if (!firewallOn) return "rules.confirmBodyOff";
  if (rule?.enabled === false) return "rules.confirmBodyRuleOff";
  return rule?.action === "deny" ? "rules.confirmBodyOnDeny" : "rules.confirmBodyOn";
}
