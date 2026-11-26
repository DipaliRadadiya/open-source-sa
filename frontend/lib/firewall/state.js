export function firewallState(enabled, policy) {
  if (enabled === null || enabled === undefined) return "unknown";
  if (!enabled) return "off";

  const incoming = typeof policy?.incoming === "string" ? policy.incoming.trim().toLowerCase() : null;

  return incoming === "allow" ? "exposed" : "on";
}

export function deleteRuleBodyKey(firewallOn, rule) {
  if (!firewallOn) return "rules.confirmBodyOff";
  if (rule?.enabled === false) return "rules.confirmBodyRuleOff";
  return rule?.action === "deny" ? "rules.confirmBodyOnDeny" : "rules.confirmBodyOn";
}
