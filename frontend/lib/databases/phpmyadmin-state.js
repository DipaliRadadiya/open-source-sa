// Hidden unless the driver is `sql` (not engine names). `installed: null` means
// the lookup failed and must not offer installing a second copy.
export function phpmyadminState({ engine, driver = null, installed = null, users = null } = {}) {
  // Engine name is only a fallback for payloads without `driver`.
  if (driver ? driver !== "sql" : engine === "mongodb") return "hidden";
  if (installed === false) return "install";
  // Only a counted zero; a missing count is not zero users.
  if (users === 0) return "needs-user";
  return "open";
}

// The list sends `users_count`, the detail payload `users`. Check the array
// first: on the detail payload the schema defaults the absent count to 0.
export function userCount(database) {
  if (Array.isArray(database?.users)) return database.users.length;
  if (typeof database?.users_count === "number") return database.users_count;
  return null;
}
