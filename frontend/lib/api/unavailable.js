// 503 while Laravel is in maintenance mode (panel update). Its own class: production
// error boundaries only receive a digest, so the throw site must identify it.
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
