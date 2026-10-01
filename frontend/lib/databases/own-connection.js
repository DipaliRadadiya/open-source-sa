/**
 * Whether a process-list row is the panel's own monitoring connection, which
 * must not be offered a Stop button. Matched on the admin username from
 * `GET /databases/connections` (the payload has no `is_self`), never on the
 * query text, which can change or be run by hand.
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
