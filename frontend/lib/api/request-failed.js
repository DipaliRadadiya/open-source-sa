/**
 * A failed server-side request, carrying what a Network tab row would show.
 *
 * The session and permission catalog are fetched on the server during SSR, so
 * the browser's Network tab never sees them; the error page is the only place
 * to explain the failure. Nothing here is secret: the API base URL is already
 * public via `NEXT_PUBLIC_*`.
 *
 * `kind` reuses the vocabulary of `read()` and `LoadFailed`.
 */
export class RequestFailedError extends Error {
  /**
   * @param method  "GET"
   * @param url     the absolute URL that was fetched
   * @param status  the HTTP status, or null when the request never completed
   * @param cause   the transport error, when there was no response at all
   */
  constructor({ method = "GET", url, status = null, cause = null, serverMessage = null, debug = false }) {
    super(`${method} ${url} ${status === null ? "failed" : `responded ${status}`}`);
    this.name = "RequestFailedError";
    this.method = method;
    this.url = url;
    this.status = status;
    this.cause = cause;
    // The API's own (already sanitised) message. `debug` means the response
    // also carried a trace (APP_DEBUG=true).
    this.serverMessage = serverMessage;
    this.debug = debug;
  }

  /**
   * Which explanation describes this:
   *
   *   4xx other the server rejected what the panel sent (often a panel/API
   *             version mismatch or a proxy rewriting the request).
   *   502/504   the web server answered but the API behind it did not
   *             (PHP-FPM stopped or timed out).
   *   5xx other the API itself errored; the reason is in its log.
   *
   * 401/419, 429 and 503 never get here: the fetchers handle them first.
   */
  get kind() {
    if (this.status === null) return "network";
    if (this.status === 403) return "forbidden";
    if (this.status === 404) return "notFound";
    if (this.status === 502 || this.status === 504) return "gateway";
    if (this.status >= 500) return "server";
    // Only 4xx is left: `fetch` follows redirects, so a 3xx never arrives here.
    return "rejected";
  }

  /** The path alone, for the request line. The host is shown separately. */
  get path() {
    try {
      return new URL(this.url).pathname;
    } catch {
      return this.url;
    }
  }

  get host() {
    try {
      return new URL(this.url).host;
    } catch {
      return null;
    }
  }
}

// By name, not instanceof: the class can be evaluated more than once across
// bundle chunks, and the name is what survives that.
export function isRequestFailed(error) {
  return error instanceof RequestFailedError || error?.name === "RequestFailedError";
}

/**
 * The props the card needs, as a plain object: an Error does not cross the
 * server/client boundary, only serialisable values do.
 */
export function requestFailureProps(error) {
  return {
    kind: error.kind,
    method: error.method,
    path: error.path,
    host: error.host,
    status: error.status,
    serverMessage: error.serverMessage,
    debug: error.debug,
  };
}
