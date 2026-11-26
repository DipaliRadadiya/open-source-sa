export class RateLimitedError extends Error {
  constructor(source) {
    super(`${source} responded 429`);
    this.name = "RateLimitedError";
  }
}

export function isRateLimited(error) {
  return error instanceof RateLimitedError || error?.name === "RateLimitedError";
}
