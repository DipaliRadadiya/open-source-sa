export class RequestFailedError extends Error {
  constructor({ method = "GET", url, status = null, cause = null, serverMessage = null, debug = false }) {
    super(`${method} ${url} ${status === null ? "failed" : `responded ${status}`}`);
    this.name = "RequestFailedError";
    this.method = method;
    this.url = url;
    this.status = status;
    this.cause = cause;
    this.serverMessage = serverMessage;
    this.debug = debug;
  }

  get kind() {
    if (this.status === null) return "network";
    if (this.status === 403) return "forbidden";
    if (this.status === 404) return "notFound";
    if (this.status === 502 || this.status === 504) return "gateway";
    if (this.status >= 500) return "server";
    // fetch follows redirects, so 3xx never gets here.
    return "rejected";
  }

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

export function isRequestFailed(error) {
  return error instanceof RequestFailedError || error?.name === "RequestFailedError";
}

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
