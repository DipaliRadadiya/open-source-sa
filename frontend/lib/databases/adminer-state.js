// What the Adminer button offers for a database. Adminer opens the `sql` and
// `pgsql` drivers; MongoDB is refused by the API, so no button.
//
// On MySQL/MariaDB phpMyAdmin is already offered, so Adminer appears there only
// once it is installed: an "Install Adminer" beside "Install phpMyAdmin" on every
// row would be two prompts for one need. PostgreSQL has no other client, so it
// gets the install link. `installed: null` (failed lookup) never offers installing.
export function adminerState({ engine, driver = null, installed = null, users = null } = {}) {
  const kind = driver ?? (engine === "mongodb" ? "mongo" : engine === "postgresql" ? "pgsql" : "sql");
  if (kind !== "sql" && kind !== "pgsql") return "hidden";
  if (installed === false) return kind === "pgsql" ? "install" : "hidden";
  if (installed === null && kind === "sql") return "hidden";
  if (users === 0) return "needs-user";
  return "open";
}
