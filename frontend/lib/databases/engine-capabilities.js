/**
 * Whether this engine's accounts can be reached from anywhere but the server.
 *
 * A tiny function for a one-line lookup, because the one-liner was wrong: it
 * called `.find()` straight on `getEngines()`, which returns
 * `{ engines, failed }` and not an array. Every `/databases/{id}` page went
 * down with "engines.find is not a function", and the `.catch` on the fetcher
 * did not help — nothing rejected. The call succeeded, returned an object, and
 * the crash happened later at the point of use.
 *
 * Takes the fetcher's result verbatim rather than a pre-dug array, so the shape
 * is handled in exactly one place and can be tested without a renderer.
 *
 * Defaults TRUE — unknown engine, failed lookup, older API with no such field:
 * all mean "keep offering the choice". Hiding a control that works is worse
 * than showing one the server will refuse with a clear message.
 */
export function supportsRemoteUsers(result, engine) {
  const rows = Array.isArray(result) ? result : (result?.engines ?? []);
  const row = rows.find((candidate) => candidate?.engine === engine);
  return row?.supports_remote_users !== false;
}
