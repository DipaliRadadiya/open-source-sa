const FORMATS = {
  sql: { accept: ".sql,.gz", key: "sql" },
  pgsql: { accept: ".sql,.gz,.dump,.backup,.pgdump", key: "pgsql" },
  mongo: { accept: ".archive,.gz,.dump", key: "mongo" },
};

export function importFormat(database) {
  const driver = database?.driver ?? (database?.engine === "mongodb" ? "mongo" : database?.engine === "postgresql" ? "pgsql" : "sql");
  return FORMATS[driver] ?? FORMATS.sql;
}

export const IMPORT_IN_FLIGHT = ["queued", "running"];

export const IMPORT_STEPS = ["uploading", "checking", "backing_up", "importing"];

export function importStage(row) {
  if (!row) return null;
  if (row.status === "uploading") return "uploading";
  if (row.status === "queued") return "checking";
  if (row.status === "running") return row.step === "rolling_back" ? "importing" : (row.step ?? "checking");
  return null;
}

const NAME_HINTS = [
  { family: "pgsql", pattern: /(^|[^a-z])(postgres(ql)?|pgsql|pg_?dump)([^a-z]|$)/i },
  { family: "mongo", pattern: /(^|[^a-z])(mongo(db|dump)?)([^a-z]|$)|\.archive(\.gz)?$/i },
  { family: "sql", pattern: /(^|[^a-z])(mysql|mariadb|mysqldump)([^a-z]|$)/i },
];

export function fileEngineFamily(fileName) {
  return NAME_HINTS.find((hint) => hint.pattern.test(fileName ?? ""))?.family ?? null;
}

export function engineMismatch(fileName, database) {
  const fromName = fileEngineFamily(fileName);
  const own = importFormat(database).key;
  return fromName && fromName !== own ? fromName : null;
}

export function hasImportAccount(database) {
  const users = database?.users;
  if (!Array.isArray(users)) return true;
  return users.some((user) => user.password_known !== false && user.connection_preference !== "remote");
}
