/**
 * Is this row mid-operation?
 *
 * The API reports four states — `installing | removing | ready | failed` — and
 * omits `status` entirely on older responses, where absent means ready. Only
 * the first two are still moving; `failed` is settled and stays until someone
 * retries, so polling for it would never end.
 */
export function isInFlight(status) {
  return status === "installing" || status === "removing";
}

export function anyInFlight(rows = []) {
  return rows.some((row) => isInFlight(row?.status));
}

/**
 * How often to re-ask while something is running, and when to give up.
 *
 * Four seconds matches the fail2ban install screen — fast enough that a step
 * change is seen rather than discovered. apt is allowed ten minutes by the
 * backend, so polling stops at fifteen: a worker that died mid-install would
 * otherwise leave the tab asking forever, and a reload is a fair price for a
 * state that is already wrong.
 */
export const RUNTIME_POLL_MS = 4000;
export const RUNTIME_POLL_STOP_MS = 15 * 60 * 1000;
