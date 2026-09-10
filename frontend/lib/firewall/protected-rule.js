/**
 * Why a panel-seeded firewall rule cannot be changed, or null.
 *
 * `ProtectedRuleGuard` locks port_from, port_to, protocol, action, source_ip
 * and `enabled` on a seeded rule — but only while the firewall is enforcing,
 * and that escape hatch turned out to be a one-way trap:
 *
 *   1. turn the firewall off
 *   2. switch the seeded port-80 rule off — allowed, nothing is enforcing
 *   3. turn the firewall on — the rule is now locked in the OFF position
 *
 * and every site on the box is unreachable with no way back through the panel.
 * Do it to port 22 and you have locked yourself off the server. Found in that
 * exact state on a live panel: `port 80, enabled=false, protected=true`.
 *
 * So the lock no longer asks whether the firewall is on. It asks which
 * DIRECTION the switch is going, which is the question that was missing:
 *
 *   OFF → ON   always allowed. Opening a seeded port cannot cut anybody off,
 *              and it is the only way out for a server already at step 3.
 *   ON → OFF   always refused, enforcing or not. This is the step that sets
 *              the trap, and refusing it while the firewall happens to be off
 *              is the entire point.
 *
 * The consequence, stated plainly: a server that genuinely needs port 80 shut
 * can no longer do it from this screen. Krishna's call, 2026-09-10 — locking
 * yourself out of your own server is the worse failure, and it is the one that
 * happens by accident.
 *
 * Pure, and in `lib/` rather than beside the component, so it can be tested
 * without a renderer: this is the decision, not the markup.
 */
export function protectedReasonFor({ rule, canManage, labels, turningOff = true } = {}) {
  if (!canManage) return labels?.noPermission ?? null;
  if (!rule?.protected) return null;
  // Switching a protected rule back ON is always safe — it only opens a port.
  return turningOff ? (labels?.protectedReason ?? null) : null;
}
