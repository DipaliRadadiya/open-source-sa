// Error boundaries only get a digest in production, so throw a named class.
export class PanelUnavailableError extends Error {
  constructor(source) {
    super(`${source} responded 503`);
    this.name = "PanelUnavailableError";
  }
}

export function isPanelUnavailable(error) {
  return error instanceof PanelUnavailableError || error?.name === "PanelUnavailableError";
}
