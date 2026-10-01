/**
 * The panel account manages every database on the server. It is not the
 * credential an application should be pointed at.
 */
const PANEL_PREFIX = "panel_";

/** The application's database user for a connection card, never the panel's own. */
export function primaryUser(database) {
  const users = database?.users ?? [];
  // The oldest, since the API list is unordered. On PostgreSQL the first user
  // is the owner, the only one with rights on existing tables.
  const own = users
    .filter((user) => !user.username.startsWith(PANEL_PREFIX))
    .sort((a, b) => a.id - b.id);
  return own[0] ?? users[0] ?? null;
}

/**
 * Host and port from the connection string; the other parts come from their
 * own authoritative fields.
 */
export function connectionAddress(user) {
  if (!user?.connection_string) return {};
  try {
    const url = new URL(user.connection_string);
    return { host: url.hostname || null, port: url.port || null };
  } catch {
    return {};
  }
}
