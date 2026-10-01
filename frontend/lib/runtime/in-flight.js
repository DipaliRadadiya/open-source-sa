// `failed` is settled, not in flight: polling it would never end.
export function isInFlight(status) {
  return status === "installing" || status === "removing";
}

export function anyInFlight(rows = []) {
  return rows.some((row) => isInFlight(row?.status));
}

// The backend allows apt ten minutes; polling stops at fifteen in case the worker died.
export const RUNTIME_POLL_MS = 4000;
export const RUNTIME_POLL_STOP_MS = 15 * 60 * 1000;
