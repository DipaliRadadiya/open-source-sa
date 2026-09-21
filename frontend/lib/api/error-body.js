/**
 * Krishna: "why we cannot see actual message instead of showing just Your
 * server returned an error… should we show why this is happening?"
 *
 * Yes. The API's own `message` IS the reason, and the backend goes to real
 * trouble to make it safe to read — `bootstrap/app.php` rewrites 404 and 405
 * into translated sentences precisely so they do not leak a model class or the
 * route map. Withholding that and printing our own guess instead was the panel
 * deciding it knew better than its own server.
 *
 * What is NOT taken: `trace`, `file`, `line`, `exception`. Those are Laravel's
 * debug-mode extras and have no reader here.
 *
 * Their presence is, however, a fact worth surfacing: they only appear when
 * `APP_DEBUG=true`, which on a panel whose login page is public is a finding in
 * itself. So the shape is reported rather than hidden — the card turns it into
 * a warning instead of quietly printing a stack trace to a stranger.
 */
const MAX = 300;

export async function readErrorBody(res) {
  const empty = { message: null, debug: false };

  // Only JSON. An HTML error page from nginx/Apache is a wall of markup, and
  // its useful part (the status) is already carried separately.
  const type = res.headers?.get?.("content-type") ?? "";
  if (!type.includes("json")) return empty;

  let data;
  try {
    data = await res.json();
  } catch {
    return empty;
  }
  if (!data || typeof data !== "object") return empty;

  const debug = ["trace", "exception", "file", "line"].some((key) => key in data);

  let message = typeof data.message === "string" ? data.message.trim() : null;
  if (!message) return { message: null, debug };

  // A validation body carries the useful part under `errors`, and its
  // `message` is the generic "The given data was invalid." Prefer the first
  // real complaint.
  const firstError =
    data.errors && typeof data.errors === "object"
      ? Object.values(data.errors).flat().find((v) => typeof v === "string")
      : null;
  if (firstError) message = firstError.trim();

  // Bounded: a debug-mode message can be a whole SQL statement, and the card
  // is 384px wide.
  if (message.length > MAX) message = `${message.slice(0, MAX - 1).trimEnd()}…`;

  return { message, debug };
}
