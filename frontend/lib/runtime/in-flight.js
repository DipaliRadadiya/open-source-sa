/**
 * Whether a row is mid-operation. The API sends `installing | removing |
 * ready | failed` (absent means ready); `failed` is settled, so polling it
 * would never end.
 */
export function isInFlight(status) {
  return status === "installing" || status === "removing";
}

export function anyInFlight(rows = []) {
  return rows.some((row) => isInFlight(row?.status));
}

/**
 * Poll interval while something runs, and when to give up. The backend allows
 * apt ten minutes, so polling stops at fifteen in case the worker died.
 */
export const RUNTIME_POLL_MS = 4000;
export const RUNTIME_POLL_STOP_MS = 15 * 60 * 1000;
