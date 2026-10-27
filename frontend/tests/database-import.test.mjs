import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { engineMismatch, fileEngineFamily, importFormat, importStage, IMPORT_STEPS } from "../lib/databases/import-formats.js";
import { importSchema, exportSchema } from "../lib/schemas/database.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

test("each engine's picker steers to the formats its import accepts", () => {
  assert.equal(importFormat({ driver: "sql" }).key, "sql");
  assert.equal(importFormat({ driver: "pgsql" }).key, "pgsql");
  assert.equal(importFormat({ driver: "mongo" }).key, "mongo");
  // Payloads without `driver` fall back on the engine name.
  assert.equal(importFormat({ engine: "postgresql" }).key, "pgsql");
  assert.equal(importFormat({ engine: "mongodb" }).key, "mongo");
  assert.match(importFormat({ driver: "sql" }).accept, /\.sql/);
});

test("the stepper follows the server's row: queued is checking, rolling back stays on importing", () => {
  assert.deepEqual(IMPORT_STEPS, ["uploading", "checking", "backing_up", "importing"]);
  assert.equal(importStage({ status: "uploading" }), "uploading");
  assert.equal(importStage({ status: "queued" }), "checking");
  assert.equal(importStage({ status: "running", step: "backing_up" }), "backing_up");
  assert.equal(importStage({ status: "running", step: "rolling_back" }), "importing");
  assert.equal(importStage({ status: "completed" }), null);
  assert.equal(importStage(null), null);
});

test("the import row and the export's purpose parse from the documented payload", () => {
  const row = importSchema.parse({
    id: 7, database_id: 1, database: "shop_db", engine: "mariadb", file_name: "shop.sql.gz",
    size_bytes: 10485760, size_human: "10 MB", received_bytes: 10485760, status: "running", step: "importing",
    reason: null, message: null, line: null, reference: null, restored: false, safety_export_id: 41,
    requested_by: { id: 1, username: "admin" }, created_at: "14-10-2026 11:02:10", finished_at: null,
  });
  assert.equal(row.step, "importing");
  assert.equal(exportSchema.parse({ id: 1, status: "completed", purpose: "import_safety" }).purpose, "import_safety");
});

test("the replacement is confirmed before the upload, and start always sends confirm_replace", () => {
  const card = read("components/databases/database-import.jsx");
  // The file is chosen first, the dialog asks, and only its confirm runs the upload.
  assert.match(card, /setChosen\(file\)/);
  assert.match(card, /onConfirm=\{run\}/);
  const api = read("lib/api/databases.js");
  assert.match(api, /\/start`, \{\s*confirm_replace: true,/);
  // Chunks are clamped below nginx's body buffer, like the file manager's.
  assert.match(api, /Math\.min\(chunkSizeFor\(file\.size\), data\.max_chunk \|\| Infinity\)/);
});

test("a refused start keeps the uploaded file, so its row can start again", () => {
  const api = read("lib/api/databases.js");
  const fn = api.slice(api.indexOf("export async function uploadAndStartImport"));
  const cleanup = fn.indexOf("api.delete(base)");
  const start = fn.indexOf("return startImport(databaseId, importId, { safetyCopy })");
  assert.ok(cleanup !== -1 && start > cleanup, "start must run after the upload's cleanup block, not inside it");
  assert.match(read("components/databases/database-import.jsx"), /row\.status === "uploading" && row\.received_bytes === row\.size_bytes/);
});

test("leaving mid-upload is guarded, and Import sits below Exports (Krishna, 15 Oct)", () => {
  assert.match(read("components/databases/database-import.jsx"), /useWatchUnsaved\("database-import", upload !== null\)/);
  const page = read("app/(app)/databases/[database]/page.jsx");
  assert.match(page, /<DatabaseExports[\s\S]*?<DatabaseImport/);
  // One number on a tab holding two lists read as both.
  assert.match(page, /exports: null,/);
  assert.match(page, /getImports\(id\)/);
  assert.match(read("components/databases/database-exports.jsx"), /row\.purpose === "import_safety"/);
});

test("the history folds after three, and each import links to the backup it took", () => {
  const card = read("components/databases/database-import.jsx");
  assert.match(card, /const HISTORY_SHOWN = 3;/);
  assert.match(card, /showAll \? history : history\.slice\(0, HISTORY_SHOWN\)/);
  assert.match(card, /exports\.find\(\(e\) => e\.id === row\.safety_export_id\)/);
  // Only a backup that is on disk and downloadable gets the button.
  assert.match(card, /found\?\.status === "completed" && found\.available && found\.download_url/);
});

test("Exports folds after five, so a long list cannot push Import out of reach", () => {
  const card = read("components/databases/database-exports.jsx");
  assert.match(card, /const ROWS_SHOWN = 5;/);
  assert.match(card, /showAll \? rows : rows\.slice\(0, ROWS_SHOWN\)/);
  // The disk-usage line still counts every kept file, not just the visible ones.
  assert.match(card, /const kept = rows\.reduce/);
});

test("a file named for another engine is flagged; MySQL and MariaDB count as one; no name, no warning", () => {
  // Krishna's case: a MariaDB export picked for a PostgreSQL database.
  assert.equal(engineMismatch("staging-my-blog-ctdzfn-mariadb-20260930-080934-dytwsg.sql", { driver: "pgsql" }), "sql");
  assert.equal(engineMismatch("shop-postgresql-2026.sql.gz", { driver: "sql" }), "pgsql");
  assert.equal(engineMismatch("app.archive.gz", { driver: "sql" }), "mongo");
  assert.equal(engineMismatch("site-mysql.sql", { engine: "mariadb", driver: "sql" }), null);
  assert.equal(engineMismatch("dump.sql", { driver: "pgsql" }), null);
  // Word boundaries: "mongoose" is not MongoDB, "pgsqlite" is not a hint either way.
  assert.equal(fileEngineFamily("mongoose-backup.sql"), null);
});
