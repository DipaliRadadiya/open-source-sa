const ACTIVE_STATUSES = new Set(["pending", "running"]);

export function shouldRecoverPanelUpdate(latestRun, previousRunId = null) {
  if (!latestRun) return false;

  return (
    ACTIVE_STATUSES.has(latestRun.status) ||
    String(latestRun.id) !== String(previousRunId ?? "")
  );
}
