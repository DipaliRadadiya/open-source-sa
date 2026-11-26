export function adminerState({ engine, driver = null, installed = null, users = null } = {}) {
  const kind = driver ?? (engine === "mongodb" ? "mongo" : engine === "postgresql" ? "pgsql" : "sql");
  if (kind !== "sql" && kind !== "pgsql") return "hidden";
  if (installed === false) return "install";
  if (installed === null && kind === "sql") return "hidden";
  if (users === 0) return "needs-user";
  return "open";
}
