// The panel account manages every database; never point an application at it.
const PANEL_PREFIX = "panel_";

/** The application's database user for a connection card, never the panel's own. */
export function primaryUser(database) {
  const users = database?.users ?? [];
  // The oldest (the API list is unordered): on PostgreSQL that is the owner of existing tables.
  const own = users
    .filter((user) => !user.username.startsWith(PANEL_PREFIX))
    .sort((a, b) => a.id - b.id);
  return own[0] ?? users[0] ?? null;
}

// Host and port only; the other parts have their own authoritative fields.
export function connectionAddress(user) {
  if (!user?.connection_string) return {};
  try {
    const url = new URL(user.connection_string);
    return { host: url.hostname || null, port: url.port || null };
  } catch {
    return {};
  }
}
