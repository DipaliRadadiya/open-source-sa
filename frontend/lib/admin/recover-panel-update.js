const ACTIVE_STATUSES = new Set(["pending", "running"]);

// A start response is ambiguous when the connection drops; recover when the server reports
// an active run, or a latest run different from the one seen before.
export function shouldRecoverPanelUpdate(latestRun, previousRunId = null) {
  if (!latestRun) return false;

  return (
    ACTIVE_STATUSES.has(latestRun.status) ||
    String(latestRun.id) !== String(previousRunId ?? "")
  );
}
