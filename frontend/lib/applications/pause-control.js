// `is_disabled` first: pausing leaves `status` "active", so a paused site would get a second Pause (422).
export function pauseControl(application, { canManage = false } = {}) {
  if (!canManage || !application) return null;
  if (application.is_disabled) return "resume";
  return application.status === "active" ? "pause" : null;
}
