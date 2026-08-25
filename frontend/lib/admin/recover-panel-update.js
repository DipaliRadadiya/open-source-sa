const ACTIVE_STATUSES = new Set(["pending", "running"]);

// A start response is ambiguous when the connection drops or client parsing
// fails. Recover when the server reports an active run, or a different latest
// run from the one visible before the button was pressed.
export function shouldRecoverPanelUpdate(latestRun, previousRunId = null) {
  if (!latestRun) return false;

  return (
    ACTIVE_STATUSES.has(latestRun.status) ||
    String(latestRun.id) !== String(previousRunId ?? "")
  );
}
