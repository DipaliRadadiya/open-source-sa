// The lock depends on switch DIRECTION, not firewall state: OFF → ON is always allowed
// (it cannot cut anyone off and escapes the off-then-locked-off trap); ON → OFF is always
// refused, so a seeded port cannot be shut here. Accidental lock-out is the worse failure.
export function protectedReasonFor({ rule, canManage, labels, turningOff = true } = {}) {
  if (!canManage) return labels?.noPermission ?? null;
  if (!rule?.protected) return null;
  // Switching a protected rule back ON is always safe — it only opens a port.
  return turningOff ? (labels?.protectedReason ?? null) : null;
}
