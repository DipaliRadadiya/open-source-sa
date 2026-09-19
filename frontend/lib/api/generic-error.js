/**
 * The last-resort error sentence, in the reader's language.
 *
 * `handleValidationError` is a plain function called from 42 places, so it
 * cannot call `useTranslations` — and it ended up shipping a hardcoded
 * "Something went wrong" to Spanish, German and Japanese readers. It was the
 * only English string left in the panel.
 *
 * The alternatives were worse. Threading a `fallback` through all 42 call sites
 * moves the same sentence into 42 files and guarantees the 43rd forgets it.
 * Returning no message at all turns "the API said nothing useful" into "nothing
 * happened", which is the failure mode the rest of this file exists to prevent.
 *
 * So the shell hands the translated sentence over once, on mount, and this
 * holds it. `errors.title` rather than a new key: the same words already sit on
 * the panel's error boundary, translated eight times, and inventing a second
 * phrasing is the vocabulary split the one-voice guard was built to catch.
 */
let message = "Something went wrong";

export function setGenericErrorMessage(next) {
  if (typeof next === "string" && next.trim()) message = next;
}

/**
 * English until the shell has mounted — which is only reachable if something
 * fails during the first paint, before any user action. Wrong language beats no
 * message.
 */
export function genericErrorMessage() {
  return message;
}
