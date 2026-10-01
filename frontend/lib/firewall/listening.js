/**
 * What is actually running behind a rule: an allow rule with nothing bound does
 * nothing, and a public service with no rule is unreachable.
 */

/**
 * The listening entry a rule points at, or null.
 *
 * Only public sockets count; a 127.0.0.1 process is unreachable from outside
 * regardless of the firewall. A range rule matches if anything public is bound
 * inside it.
 */
export function listenerFor(rule, listening = []) {
  const from = Number(rule?.port_from);
  if (!Number.isFinite(from)) return null;
  const to = Number.isFinite(Number(rule?.port_to)) ? Number(rule.port_to) : from;
  const proto = rule?.protocol;

  return (
    listening.find(
      (entry) =>
        entry.public !== false &&
        entry.port >= Math.min(from, to) &&
        entry.port <= Math.max(from, to) &&
        // "all" matches either; otherwise the protocols have to agree.
        (!proto || proto === "all" || !entry.protocol || entry.protocol === proto),
    ) ?? null
  );
}

/**
 * Public ports with nothing allowing them through — a service running but
 * unreachable. Only meaningful while the firewall is actually enforcing.
 */
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
