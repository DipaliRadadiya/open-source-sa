/**
 * Why a panel-seeded firewall rule cannot be changed, or null.
 *
 * `ProtectedRuleGuard` locks a seeded rule's fields only while the firewall is
 * enforcing, which allows a trap: turn the firewall off, switch the seeded
 * port-80 (or 22) rule off, turn the firewall on, and the rule is locked OFF
 * with no way back through the panel.
 *
 * So the lock depends on the switch DIRECTION, not on the firewall state:
 * OFF → ON   always allowed; opening a seeded port cannot cut anyone off, and
 *            it is the only way out of the trap.
 * ON → OFF   always refused, enforcing or not.
 *
 * Consequence: a seeded port cannot be shut from this screen; accepted, since
 * accidental lock-out is the worse failure.
 *
 * Pure and in `lib/` so the decision can be tested without a renderer.
 */
export function protectedReasonFor({ rule, canManage, labels, turningOff = true } = {}) {
  if (!canManage) return labels?.noPermission ?? null;
  if (!rule?.protected) return null;
  // Switching a protected rule back ON is always safe — it only opens a port.
  return turningOff ? (labels?.protectedReason ?? null) : null;
}
