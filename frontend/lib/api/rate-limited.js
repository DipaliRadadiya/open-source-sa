/**
 * A 429 from the per-user rate limit: "ask again shortly", never a crash or
 * "no permissions". Thrown from the panel layout's session/permission fetches,
 * which sit above every error.jsx, so the layout catches it by identity.
 */
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
