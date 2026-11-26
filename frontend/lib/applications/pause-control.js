export function pauseControl(application, { canManage = false } = {}) {
  if (!canManage || !application) return null;
  if (application.is_disabled) return "resume";
  return application.status === "active" ? "pause" : null;
}
