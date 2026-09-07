/**
 * Whether a row in the process list is the panel talking to itself.
 *
 * The Running queries card exists to find something stuck and stop it. On a
 * quiet server the ONLY row is the panel's own monitoring connection — the one
 * that produced the list you are reading — so the single Stop button on screen
 * kills the thing keeping the page alive. Reported as "the database in
 * Processes should not stop".
 *
 * Matched on the admin username the panel connects as, which
 * `GET /databases/connections` reports per engine. Nothing in the process
 * payload marks itself, and there is no `is_self` to ask for: the username is
 * the only honest signal available on this side.
 *
 * Deliberately NOT matched on the query text. "SHOW GLOBAL STATUS" is what the
 * panel happens to run today; a rule written against it would silently stop
 * protecting anything the moment that query changed, and would wrongly block
 * an operator running the same statement by hand.
 */

/** The usernames the panel itself connects as, for the engine in view. */
export function panelUsernames(connections = [], engine) {
  return new Set(
    (Array.isArray(connections) ? connections : [])
      .filter((row) => !engine || row?.engine === engine)
      .map((row) => row?.username)
      .filter(Boolean)
      .map(String),
  );
}

/**
 * MySQL reports `user` plainly; some drivers append the host as `user@host`.
 * Compared on the part before the `@` so both shapes match.
 */
export function isPanelProcess(process, usernames) {
  const raw = process?.user;
  if (!raw || !usernames || usernames.size === 0) return false;
  return usernames.has(String(raw).split("@")[0]);
}
