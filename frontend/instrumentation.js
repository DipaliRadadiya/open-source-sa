// TEMPORARY (2026-07-29) — diagnostic only. REMOVE once digest 705774304 is
// fixed.
//
// Next redacts server error messages in production builds, so the browser only
// ever shows a digest. The agent working on this project cannot read the
// service journal (not in `adm`/`systemd-journal`), so `onRequestError` — Next's
// official server-error hook — mirrors the real error to a file it can read.
// `*.log` is gitignored, so nothing here is committed.

const LOG_PATH =
  process.env.SSR_ERROR_LOG || "/var/www/sv-oss/frontend/ssr-errors.log";

export async function onRequestError(error, request, context) {
  // The hook also runs on the edge runtime, where node:fs doesn't exist.
  if (process.env.NEXT_RUNTIME !== "nodejs") return;

  try {
    const { appendFile } = await import("node:fs/promises");
    const entry = [
      `--- ${new Date().toISOString()}`,
      `path:    ${request?.path ?? "?"}`,
      `type:    ${context?.routerKind ?? "?"} ${context?.routeType ?? ""}`,
      `digest:  ${error?.digest ?? "-"}`,
      `message: ${error?.message ?? String(error)}`,
      String(error?.stack ?? "")
        .split("\n")
        .slice(1, 12)
        .join("\n"),
      "",
    ].join("\n");
    await appendFile(LOG_PATH, entry + "\n");
  } catch {
    // Diagnostics must never take the request down with them.
  }
}
