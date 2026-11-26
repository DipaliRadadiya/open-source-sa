
// Only meaningful while the firewall is actually enforcing.
export function unreachablePorts({ listening = [], rules = [], enabled }) {
  if (!enabled) return [];

  return listening
    .filter((entry) => entry.public !== false)
    .filter(
      (entry) =>
        !rules.some(
          (rule) =>
            rule.enabled !== false &&
            rule.action !== "deny" &&
            entry.port >= Math.min(rule.port_from, rule.port_to ?? rule.port_from) &&
            entry.port <= Math.max(rule.port_from, rule.port_to ?? rule.port_from),
        ),
    );
}
