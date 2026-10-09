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

// Host, port and protocol; the other parts have their own authoritative fields.
// `connection` reaches view-only roles too; the string only adds the protocol.
export function connectionAddress(user) {
  const fromString = addressFromString(user);
  if (!user?.connection) return fromString;
  return {
    ...fromString,
    host: user.connection.host ?? fromString.host ?? null,
    port: user.connection.port != null ? String(user.connection.port) : (fromString.port ?? null),
  };
}

function addressFromString(user) {
  if (!user?.connection_string) return {};
  try {
    const url = new URL(user.connection_string);
    return {
      host: url.hostname || null,
      port: url.port || null,
      protocol: url.protocol.replace(/:$/, "") || null,
    };
  } catch {
    return {};
  }
}

/** The string split for display, password left out; null when it is not a URL. */
export function connectionStringParts(value) {
  if (!value) return null;
  try {
    const url = new URL(value);
    return {
      scheme: url.protocol.replace(/:$/, ""),
      username: decodeURIComponent(url.username),
      host: url.hostname,
      port: url.port,
      // Path and query: the database name, plus Mongo's options.
      rest: `${url.pathname}${url.search}`.replace(/^\//, ""),
    };
  } catch {
    return null;
  }
}
