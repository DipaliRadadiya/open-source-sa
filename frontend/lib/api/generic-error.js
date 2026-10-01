// Error sentences for plain functions that cannot call `useTranslations`; the app
// shell hands the translated strings over once, on mount.
let message = "Something went wrong";

export function setGenericErrorMessage(next) {
  if (typeof next === "string" && next.trim()) message = next;
}

/** English until the shell has mounted (only possible during first paint). */
export function genericErrorMessage() {
  return message;
}

/** Replaces Laravel's untranslated 429 "Too Many Attempts." */
let rateLimited = "Too many requests. Wait a moment and try again.";

export function setRateLimitedMessage(next) {
  if (typeof next === "string" && next.trim()) rateLimited = next;
}

export function rateLimitedMessage() {
  return rateLimited;
}

/** No answer at all: the server may still have done it, so no "could not ..." fallback. */
let noAnswer =
  "No answer from the server. It may or may not have happened — reload the page to see where it stands.";

export function setNoAnswerMessage(next) {
  if (typeof next === "string" && next.trim()) noAnswer = next;
}

export function noAnswerMessage() {
  return noAnswer;
}
