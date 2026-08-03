/**
 * What is actually running behind a rule.
 *
 * A firewall page without this shows rules and hopes: an allow rule for 8080
 * with nothing bound to 8080 does nothing at all, and a service bound publicly
 * with no rule is unreachable. Both are common, and neither is visible from the
 * rule list alone.
 */

/**
 * The listening entry a rule points at, or null.
 *
 * Only PUBLIC sockets count. A process on 127.0.0.1 cannot be reached from
 * outside whatever the firewall says, so calling its rule "in use" would be
 * wrong in the direction that matters.
 *
 * Range rules match if anything public is bound inside the range — one live
 * port is enough to make the rule real.
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
