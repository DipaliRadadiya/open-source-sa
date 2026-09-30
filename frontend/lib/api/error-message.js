import { rateLimitedMessage } from "./generic-error.js";

/**
 * The message to show a person when a request fails.
 *
 * The API usually sends a written sentence, and that's better than anything the
 * frontend could compose because it knows what actually went wrong. But it
 * sometimes sends an untranslated lookup key instead — `errors/php.operation_failed`
 * from a 500 — and putting that in a toast tells the reader nothing while looking
 * like the panel is broken. Key-shaped strings are therefore ignored in favour of
 * our own copy.
 *
 * A key has no spaces and is built from slashes/dots; any real sentence has spaces.
 *
 * The `reference` is appended when the API sends one. It exists so a person can
 * quote a failure to whoever can look it up, and several of the backend's own
 * sentences instruct them to — "Quote the reference below to support." — while
 * every toast in the panel but three threw it away. So the reader was told to
 * quote something the screen never showed them.
 *
 * Pass `{ reference: false }` where the caller renders the reference itself —
 * `showActionError` gives it its own line, in monospace, with a copy button,
 * which is better than a sentence with an id stuck on the end. Those callers
 * would otherwise show it twice.
 *
 * Appended here rather than at each call site: ten files had already hand-rolled
 * the same `[message, reference].join(" · ")` — thirteen copies between them —
 * and the hundred-odd other callers had not. That is what a fix belonging in one
 * place looks like when it is not in one place. The middot form is theirs, kept
 * so nothing changes shape, and those thirteen copies are now gone.
 */
// Laravel's own words for a throttled or crashed request: never translated,
// and "Server Error" says less than the caller's own fallback.
const FRAMEWORK_RATE_LIMIT = /^too many (attempts|requests)\.?$/i;
const FRAMEWORK_SERVER_ERROR = /^server error\.?$/i;

export function apiMessage(error, fallback, { reference: withReference = true } = {}) {
  const data = error?.response?.data;
  const message = data?.message;
  const trimmed = typeof message === "string" ? message.trim() : "";

  if (error?.response?.status === 429 && (!trimmed || FRAMEWORK_RATE_LIMIT.test(trimmed))) {
    return rateLimitedMessage();
  }
  const reference =
    withReference && typeof data?.reference === "string" ? data.reference.trim() : "";

  const usable = FRAMEWORK_SERVER_ERROR.test(trimmed) ? "" : trimmed;
  // Key-shaped, empty or missing: our own copy, which is written for a reader.
  const sentence = !usable || (!/\s/.test(usable) && /[/.]/.test(usable)) ? fallback : usable;

  if (!reference) return sentence;
  // A message that already carries it — some endpoints interpolate the
  // reference into their own prose — must not carry it twice.
  if (typeof sentence === "string" && sentence.includes(reference)) return sentence;
  if (!sentence) return reference;

  return `${sentence} · ${reference}`;
}
