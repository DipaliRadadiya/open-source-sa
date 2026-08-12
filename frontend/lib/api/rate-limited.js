/**
 * The API allows a fixed number of requests per minute per user. Going over it
 * is not a fault and not a signed-out session — it is "ask again shortly" — so
 * it must not reach an error boundary as an anonymous 500, and it must never be
 * mistaken for "this user has no permissions".
 *
 * The session and the permission catalog are fetched by the panel layout, which
 * sits ABOVE every error.jsx in the tree: a throw there escapes to Next's own
 * unstyled error page. So the layout catches this one by identity and renders a
 * screen that says what happened.
 */
export class RateLimitedError extends Error {
  constructor(source) {
    super(`${source} responded 429`);
    this.name = "RateLimitedError";
  }
}

// instanceof alone is unreliable across bundle chunks, where the class can be
// evaluated more than once; the name is what actually survives.
export function isRateLimited(error) {
  return error instanceof RateLimitedError || error?.name === "RateLimitedError";
}
