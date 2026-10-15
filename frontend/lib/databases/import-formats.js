// What each engine's import accepts (API_REFERENCE "Database import"). The server
// judges by the file's bytes, so this only steers the picker and the hint.
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

// The four stages the card shows, in order. `uploading` is the browser's part.
export const IMPORT_STEPS = ["uploading", "checking", "backing_up", "importing"];

/** Which stage a row is at, for the stepper. */
export function importStage(row) {
  if (!row) return null;
  if (row.status === "uploading") return "uploading";
  if (row.status === "queued") return "checking";
  if (row.status === "running") return row.step === "rolling_back" ? "importing" : (row.step ?? "checking");
  return null;
}

// Which engine a file name points at, if it says. Export names carry the engine
// ("shop-mariadb-20260930.sql"); the panel's own and most hosts' do. Null when
// the name says nothing, so an unnamed file never gets a warning.
const NAME_HINTS = [
  { family: "pgsql", pattern: /(^|[^a-z])(postgres(ql)?|pgsql|pg_?dump)([^a-z]|$)/i },
  { family: "mongo", pattern: /(^|[^a-z])(mongo(db|dump)?)([^a-z]|$)|\.archive(\.gz)?$/i },
  { family: "sql", pattern: /(^|[^a-z])(mysql|mariadb|mysqldump)([^a-z]|$)/i },
];

export function fileEngineFamily(fileName) {
  return NAME_HINTS.find((hint) => hint.pattern.test(fileName ?? ""))?.family ?? null;
}

/** The family the file name points at, when it is not this database's. */
export function engineMismatch(fileName, database) {
  const fromName = fileEngineFamily(fileName);
  const own = importFormat(database).key;
  return fromName && fromName !== own ? fromName : null;
}
