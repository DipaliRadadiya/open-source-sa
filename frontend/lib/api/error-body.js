const MAX = 300;

export async function readErrorBody(res) {
  const empty = { message: null, debug: false };

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

  const firstError =
    data.errors && typeof data.errors === "object"
      ? Object.values(data.errors).flat().find((v) => typeof v === "string")
      : null;
  if (firstError) message = firstError.trim();

  if (message.length > MAX) message = `${message.slice(0, MAX - 1).trimEnd()}…`;

  return { message, debug };
}
