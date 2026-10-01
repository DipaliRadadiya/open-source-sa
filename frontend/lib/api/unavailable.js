/**
 * The API answers 503 while Laravel is in maintenance mode (during a panel
 * update). Its own error class because production error boundaries only receive
 * a digest, so the throw site must identify it. An update that fails between
 * `artisan down` and `artisan up` leaves the panel here, so the UI names the fix.
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
