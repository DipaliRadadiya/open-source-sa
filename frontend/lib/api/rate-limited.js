// A per-user 429. Thrown above every error.jsx, so the panel layout catches it by identity.
export class RateLimitedError extends Error {
  constructor(source) {
    super(`${source} responded 429`);
    this.name = "RateLimitedError";
  }
}

// By name, not instanceof: the class can be evaluated more than once across
// bundle chunks, and the name is what survives that.
export function isRateLimited(error) {
  return error instanceof RateLimitedError || error?.name === "RateLimitedError";
}
