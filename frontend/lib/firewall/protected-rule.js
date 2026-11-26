// Turning a protected rule on is always allowed, turning it off never is, so a seeded port can't be closed here.
export function protectedReasonFor({ rule, canManage, labels, turningOff = true } = {}) {
  if (!canManage) return labels?.noPermission ?? null;
  if (!rule?.protected) return null;
  return turningOff ? (labels?.protectedReason ?? null) : null;
}
