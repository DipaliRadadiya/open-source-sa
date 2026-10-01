/**
 * The API's own error `message` from a failed response; the backend makes it
 * safe to show (e.g. 404/405 are rewritten into translated sentences).
 *
 * Laravel's debug extras (`trace`, `file`, `line`, `exception`) are never
 * shown, but their presence is reported as `debug` (APP_DEBUG=true) so the UI
 * can warn about it.
 */
const MAX = 300;

export async function readErrorBody(res) {
  const empty = { message: null, debug: false };

  // JSON only; a proxy's HTML error page adds nothing beyond the status.
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

  // Prefer the first validation error over the generic 422 `message`.
  const firstError =
    data.errors && typeof data.errors === "object"
      ? Object.values(data.errors).flat().find((v) => typeof v === "string")
      : null;
  if (firstError) message = firstError.trim();

  // Bounded: a debug-mode message can be a whole SQL statement.
  if (message.length > MAX) message = `${message.slice(0, MAX - 1).trimEnd()}…`;

  return { message, debug };
}
