/**
 * The panel account manages every database on the server. It is not the
 * credential an application should be pointed at.
 */
const PANEL_PREFIX = "panel_";

/**
 * The user whose credentials a connection card should show.
 *
 * The one someone created for their application, not the panel's own — which
 * is usually first in the list and would otherwise be the one copied into a
 * `wp-config.php`.
 */
export function primaryUser(database) {
  const users = database?.users ?? [];
  return users.find((user) => !user.username.startsWith(PANEL_PREFIX)) ?? users[0] ?? null;
}

/**
 * Host and port for a user, taken from the connection string.
 *
 * Only these two: the name, username and password are returned as their own
 * fields and are authoritative, while the address the engine answers on appears
 * nowhere else in the response.
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
