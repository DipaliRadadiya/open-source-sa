import { noAnswerMessage, rateLimitedMessage } from "./generic-error.js";

// Laravel's untranslated text for a throttled or crashed request; the fallback says more.
const FRAMEWORK_RATE_LIMIT = /^too many (attempts|requests)\.?$/i;
const FRAMEWORK_SERVER_ERROR = /^server error\.?$/i;

// Reads are left to their own fallback: "could not be read" is true when nothing came back.
export function isUnansweredWrite(error) {
  if (!error?.isAxiosError || error.response || error.code === "ERR_CANCELED") return false;
  const method = String(error.config?.method ?? "get").toLowerCase();
  return !["get", "head", "options"].includes(method);
}

// Ignores key-shaped strings (`errors/php.operation_failed`) in favour of `fallback`.
// Appends ` · ref`; pass `{ reference: false }` where the caller renders it separately.
export function apiMessage(error, fallback, { reference: withReference = true } = {}) {
  if (isUnansweredWrite(error)) return noAnswerMessage();

  const data = error?.response?.data;
  const message = data?.message;
  const trimmed = typeof message === "string" ? message.trim() : "";

  if (error?.response?.status === 429 && (!trimmed || FRAMEWORK_RATE_LIMIT.test(trimmed))) {
    return rateLimitedMessage();
  }
  const reference =
    withReference && typeof data?.reference === "string" ? data.reference.trim() : "";

  const usable = FRAMEWORK_SERVER_ERROR.test(trimmed) ? "" : trimmed;
  // Key-shaped, empty or missing: use the fallback.
  const sentence = !usable || (!/\s/.test(usable) && /[/.]/.test(usable)) ? fallback : usable;

  if (!reference) return sentence;
  // Some endpoints already interpolate the reference into their message.
  if (typeof sentence === "string" && sentence.includes(reference)) return sentence;
  if (!sentence) return reference;

  return `${sentence} · ${reference}`;
}
