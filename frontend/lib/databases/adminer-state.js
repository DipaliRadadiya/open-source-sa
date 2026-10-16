// What the Adminer button offers for a database. Adminer opens the `sql` and
// `pgsql` drivers; MongoDB is refused by the API, so no button.
//
// Not installed: offered everywhere, beside phpMyAdmin too (Krishna, 16 Oct: hiding it
// meant nobody learned Adminer was an option). `installed: null` (failed lookup) never
// offers installing; on MySQL/MariaDB phpMyAdmin still covers that case.
export function adminerState({ engine, driver = null, installed = null, users = null } = {}) {
  const kind = driver ?? (engine === "mongodb" ? "mongo" : engine === "postgresql" ? "pgsql" : "sql");
  if (kind !== "sql" && kind !== "pgsql") return "hidden";
  if (installed === false) return "install";
  if (installed === null && kind === "sql") return "hidden";
  if (users === 0) return "needs-user";
  return "open";
}
