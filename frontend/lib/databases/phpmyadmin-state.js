// installed: null means the lookup failed; don't offer a second install.
export function phpmyadminState({ engine, driver = null, installed = null, users = null } = {}) {
  if (driver ? driver !== "sql" : engine === "mongodb") return "hidden";
  if (installed === false) return "install";
  if (users === 0) return "needs-user";
  return "open";
}

export function userCount(database) {
  if (Array.isArray(database?.users)) return database.users.length;
  if (typeof database?.users_count === "number") return database.users_count;
  return null;
}
