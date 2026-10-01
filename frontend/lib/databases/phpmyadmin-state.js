/**
 * Which phpMyAdmin control a database gets, decided before the click:
 *
 *   hidden      Driver is not `sql`, matching `IssuePhpmyadminSsoToken`.
 *               Branch on the driver, not engine names.
 *   install     No active phpMyAdmin site (site_type=phpmyadmin AND
 *               status=active, as the backend checks).
 *   needs-user  phpMyAdmin signs in as a database user; there is none.
 *   open        Nothing visible blocks it; the SSO call may still refuse,
 *               and the toast handles that.
 *
 * `installed` is three-valued: `null` means the lookup failed, which must not
 * trigger an offer to install a second copy.
 */
export function phpmyadminState({ engine, driver = null, installed = null, users = null } = {}) {
  // Engine name is only a fallback for payloads without `driver`.
  if (driver ? driver !== "sql" : engine === "mongodb") return "hidden";
  if (installed === false) return "install";
  // Only a counted zero; a missing count is not zero users.
  if (users === 0) return "needs-user";
  return "open";
}

/**
 * A database row's user count: the list sends `users_count`, the detail
 * payload the `users` array. The array must be checked first: on the detail
 * payload the schema defaults the absent count to 0.
 */
export function userCount(database) {
  if (Array.isArray(database?.users)) return database.users.length;
  if (typeof database?.users_count === "number") return database.users_count;
  return null;
}
