/**
 * Krishna: "we cannot define what is issue from error page… not showing api in
 * network tab so how user will identify that what is issue?"
 *
 * Both halves of that are true, and the second one is why the first matters so
 * much. The session and the permission catalog are fetched **on the server**
 * during SSR, so when one of them fails there is no entry in the browser's
 * Network tab to open — the request happened on a machine the reader cannot
 * see. The error page is not one clue among several; it is the only one there
 * will ever be. A digest gives them nothing to act on.
 *
 * So the failure carries what a Network tab row would have shown — method,
 * path, host, status — and the page prints it. None of it is secret: the API
 * base URL is `NEXT_PUBLIC_*`, already in the client bundle, and the status is
 * the user's own server answering about their own account.
 *
 * `kind` deliberately reuses the vocabulary `read()` and `LoadFailed` already
 * use for failures inside a page, so the whole panel explains a failure the
 * same way whether it took out a card or the entire screen.
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
    // What the API itself said, already sanitised by its own exception
    // handler. `debug` means the response also carried a trace, which only
    // happens with APP_DEBUG=true.
    this.serverMessage = serverMessage;
    this.debug = debug;
  }

  /**
   * Which explanation describes this.
   *
   * Krishna: "and what message now it will show for other status codes?"
   *
   * It used to answer "server" for everything that was not 403 or 404, so a
   * 400 or a 405 was reported as "something failed inside the API" — which is
   * the opposite of what a 4xx means, and sends the reader to the wrong log.
   * Each group now gets the cause that is actually true of it:
   *
   *   4xx other the server REJECTED what the panel sent — not an internal
   *             fault. Usually panel and API on different versions, or
   *             something in between rewriting the request.
   *   502/504   the web server answered but the API behind it did not. A
   *             different fix from a 500: PHP-FPM stopped, or the request
   *             timed out. Worth its own message because it is common and
   *             the reader would otherwise go reading an empty Laravel log.
   *   5xx other the API itself errored; the reason is in ITS log.
   *
   * 401/419 (signed out), 429 (rate limited) and 503 (maintenance) never get
   * here — the fetchers answer those before throwing.
   */
  get kind() {
    if (this.status === null) return "network";
    if (this.status === 403) return "forbidden";
    if (this.status === 404) return "notFound";
    if (this.status === 502 || this.status === 504) return "gateway";
    if (this.status >= 500) return "server";
    // Anything left is a 4xx. A 3xx cannot reach here: `fetch` follows
    // redirects, so an http->https API address either resolves or fails as a
    // transport error, which is already `network` above. Verified by driving
    // a 301 against a stub — it arrives as "no reply", not as a status.
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
 * The props the card needs, as a plain object.
 *
 * A thrown Error does not cross the server/client boundary as itself — only
 * serialisable values do — so the page reads this and passes the result.
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
