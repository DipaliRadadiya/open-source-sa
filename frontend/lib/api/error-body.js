// The API's `message` is safe to show. Laravel debug extras are never shown, but
// their presence is reported as `debug` so the UI can warn.
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
