// SSR fetches never reach the browser's Network tab, so the error page explains
// them. Nothing here is secret: the API base URL is public via `NEXT_PUBLIC_*`.
export class RequestFailedError extends Error {
  // `status` is null when the request never completed; `cause` is then the transport error.
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

  // 502/504: the API behind the web server did not answer. 401/419, 429 and 503
  // never get here: the fetchers handle them first.
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

// An Error does not cross the server/client boundary; plain values do.
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
