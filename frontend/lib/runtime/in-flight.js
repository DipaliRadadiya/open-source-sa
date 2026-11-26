// failed is settled; polling it would never stop.
export function isInFlight(status) {
  return status === "installing" || status === "removing";
}

export function anyInFlight(rows = []) {
  return rows.some((row) => isInFlight(row?.status));
}

export const RUNTIME_POLL_MS = 4000;
export const RUNTIME_POLL_STOP_MS = 15 * 60 * 1000;
