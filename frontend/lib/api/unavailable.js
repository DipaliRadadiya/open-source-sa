/**
 * The API answers 503 while Laravel is in maintenance mode — which is step two
 * of a panel update (`artisan down --retry=60`). That is not a fault, not a
 * signed-out session and not a permissions problem: the server is deliberately
 * refusing everyone for a minute.
 *
 * It needs its own identity for the same reason `RateLimitedError` does: the
 * session is fetched above every error.jsx, and in a production build the
 * boundary receives only a digest, so by the time it renders there is nothing
 * left to tell a 503 apart from a crash. The caller has to decide.
 *
 * Why it matters more than it looks: an update that dies after `artisan down`
 * and before `artisan up` leaves the panel in this state permanently. The
 * generic card sends the reader looking for a frontend bug that is not there —
 * this one names the cause and the one command that ends it.
 */
export class PanelUnavailableError extends Error {
  constructor(source) {
    super(`${source} responded 503`);
    this.name = "PanelUnavailableError";
  }
}

// By name, not instanceof: the class can be evaluated more than once across
// bundle chunks, and the name is what survives that.
export function isPanelUnavailable(error) {
  return error instanceof PanelUnavailableError || error?.name === "PanelUnavailableError";
}
