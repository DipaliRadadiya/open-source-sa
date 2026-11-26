// The panel's own account manages every database; never point an app at it.
const PANEL_PREFIX = "panel_";

export function primaryUser(database) {
  const users = database?.users ?? [];
  const own = users
    .filter((user) => !user.username.startsWith(PANEL_PREFIX))
    .sort((a, b) => a.id - b.id);
  return own[0] ?? users[0] ?? null;
}

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

export function connectionStringParts(value) {
  if (!value) return null;
  try {
    const url = new URL(value);
    return {
      scheme: url.protocol.replace(/:$/, ""),
      username: decodeURIComponent(url.username),
      host: url.hostname,
      port: url.port,
      rest: `${url.pathname}${url.search}`.replace(/^\//, ""),
    };
  } catch {
    return null;
  }
}
