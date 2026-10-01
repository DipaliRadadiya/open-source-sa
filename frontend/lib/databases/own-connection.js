// The panel's own monitoring connection must not get a Stop button. Matched on the admin username
// (the payload has no `is_self`), never on the query text, which can change or be run by hand.

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

// Some drivers append the host as `user@host`; compared on the part before the `@`.
export function isPanelProcess(process, usernames) {
  const raw = process?.user;
  if (!raw || !usernames || usernames.size === 0) return false;
  return usernames.has(String(raw).split("@")[0]);
}
