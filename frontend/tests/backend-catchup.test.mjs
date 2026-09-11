import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  createDatabaseSchema,
  reservedNames,
  RESERVED_NAMES,
  engineSchema,
} from "../lib/schemas/database.js";
import { backupSchema } from "../lib/schemas/backup.js";
import { supportsRemoteUsers } from "../lib/databases/engine-capabilities.js";

/*
 * Three fields the backend shipped on 2026-09-11 after the frontend asked for
 * them. Each was a place the panel had been guessing, and a field that arrives
 * but is never read is the same as one that was never sent.
 *
 * Payloads below are real captures from the live API.
 */

// GET /databases/engines — the engine's own databases, per engine.
const ENGINES = [
  { engine: "mariadb", driver: "sql", running: true, supports_remote_users: true, system_schemas: ["information_schema", "mysql", "performance_schema", "sys"] },
  { engine: "mongodb", driver: "mongo", running: true, supports_remote_users: true, system_schemas: ["admin", "config", "local"] },
  // PostgreSQL roles carry no host — this is the one that reports false.
  { engine: "postgresql", driver: "pgsql", running: true, supports_remote_users: false, system_schemas: ["postgres", "template0", "template1"] },
];

test("the engine's system databases survive parsing", () => {
  // Zod strips what is not declared. Without the field on the schema the list
  // would arrive and vanish, and `reservedNames()` would go on using the
  // hardcoded fallback while looking like it read the server.
  const parsed = engineSchema.parse(ENGINES[2]);
  assert.deepEqual(parsed.system_schemas, ["postgres", "template0", "template1"]);
});

test("reserved names come from the server, not from this bundle", () => {
  const names = reservedNames(ENGINES);
  for (const owned of ["mysql", "information_schema", "admin", "postgres", "template0"]) {
    assert.ok(names.has(owned), `${owned} is a name the server owns`);
  }
  // A name no engine claims stays available.
  assert.equal(names.has("wordpress"), false);
});

test("an engine the frontend has never heard of still gets its names respected", () => {
  /*
   * The point of reading the list: a hardcoded copy is wrong the first time an
   * engine is added, and nothing tells anyone. This one is invented — the
   * frontend knows nothing about it — and its names are still refused.
   */
  const names = reservedNames([{ engine: "cockroach", system_schemas: ["crdb_internal"] }]);
  assert.ok(names.has("crdb_internal"));
});

test("an API that sends no system databases loses nothing", () => {
  // Older backend: no `system_schemas` anywhere. The fixed list still applies.
  const names = reservedNames([{ engine: "mariadb" }, { engine: "mongodb" }]);
  for (const owned of RESERVED_NAMES) assert.ok(names.has(owned), `${owned} fell out of the fallback`);
  assert.equal(reservedNames([]).size, RESERVED_NAMES.length);
});

test("the create form refuses a name the chosen server owns", () => {
  const schema = createDatabaseSchema(reservedNames(ENGINES));
  const base = { engine: "postgresql", create_user: false, connection_preference: "localhost" };
  const codes = (name) => {
    const result = schema.safeParse({ ...base, name });
    return result.success ? [] : result.error.issues.map((i) => i.message);
  };
  assert.ok(codes("template1").includes("databaseNameReserved"));
  assert.ok(codes("information_schema").includes("databaseNameReserved"));
  assert.deepEqual(codes("shop_live"), []);
});

test("called with no engines the schema behaves exactly as the constant did", () => {
  // The factory replaced an exported constant. Existing callers must not shift.
  const schema = createDatabaseSchema();
  const result = schema.safeParse({
    name: "postgres",
    engine: "mariadb",
    create_user: false,
    connection_preference: "localhost",
  });
  assert.equal(result.success, false);
});

test("a backup carries the name it has in the bucket", () => {
  /*
   * `id` is an autoincrement meaningful only inside one panel's database, so it
   * cannot identify an object to someone looking at the destination directly.
   * That is the entire reason `uid` exists.
   */
  const parsed = backupSchema.parse({
    id: 21,
    uid: "494acc3d-634b-4ae5-be7b-e6b347afb595",
    type: "full",
    status: "verified",
    storage_destination_name: "Cloudflare",
  });
  assert.equal(parsed.uid, "494acc3d-634b-4ae5-be7b-e6b347afb595");
  // Rows written before the column existed have none, and must still parse.
  assert.equal(backupSchema.parse({ id: 3, type: "full", status: "verified" }).uid, undefined);
});

test("the bucket id sits with the destination, and says what it copies", () => {
  const table = readFileSync(
    new URL("../components/backups/backups-history-table.jsx", import.meta.url),
    "utf8",
  );
  // Beside the destination: "Cloudflare" and a UUID are useless apart.
  assert.match(table, /storage_destination_name: name, uid/);
  // A bare "Copy" next to a destination name reads as copying the name.
  assert.match(table, /label=\{t\("copyUid"\)\}/);
});

test("the process count is the server's, not the row count", () => {
  /*
   * The list is the top `meta.limit` by CPU. The card had no number but its own
   * length, so it said 25 on every server and never moved when a process was
   * stopped.
   */
  const fetcher = readFileSync(
    new URL("../lib/server/get-server-processes.js", import.meta.url),
    "utf8",
  );
  assert.match(fetcher, /json\?\.meta\?\.total/);
  assert.match(fetcher, /Number\.isFinite\(total\) \? total : null/, "an absent total must not become 0");

  const table = readFileSync(
    new URL("../components/dashboard/process-table.jsx", import.meta.url),
    "utf8",
  );
  // Only when the server said so AND it is more than the rows shown — otherwise
  // "Top 25 of 25" is the sentence that caused the confusion in the first place.
  assert.match(table, /total != null && total > data\.length/);
});

test("every new message key resolves in every locale", () => {
  for (const locale of ["en", "es", "hi"]) {
    const messages = JSON.parse(
      readFileSync(new URL(`../messages/${locale}.json`, import.meta.url), "utf8"),
    );
    assert.ok(messages.serverDashboard.processes.summaryOfTotal, `${locale}: summaryOfTotal`);
    assert.ok(messages.backups.history.copyUid, `${locale}: copyUid`);
    // The placeholder the count is printed through — a typo here renders raw.
    assert.match(messages.serverDashboard.processes.summaryOfTotal, /\{total\}/);
  }
});

test("the engine lookup takes the fetcher's real shape, not the one I assumed", () => {
  /*
   * This shipped broken. The page called `.find()` straight on `getEngines()`,
   * which returns `{ engines, failed }` — so every /databases/{id} rendered
   * "engines.find is not a function", and the `.catch` on the fetcher never
   * fired because nothing rejected: it succeeded, handed back an object, and
   * the crash came later at the point of use.
   */
  const wrapped = { engines: ENGINES, failed: false };
  assert.equal(supportsRemoteUsers(wrapped, "postgresql"), false);
  assert.equal(supportsRemoteUsers(wrapped, "mariadb"), true);

  // A bare array works too — the shape is handled here, once, not guessed.
  assert.equal(supportsRemoteUsers(ENGINES, "postgresql"), false);

  // Every way the lookup can come up empty must keep the choice offered.
  for (const empty of [undefined, null, [], { engines: [] }, { failed: true }]) {
    assert.equal(supportsRemoteUsers(empty, "postgresql"), true, JSON.stringify(empty));
  }
  // An engine the row does not mention, and one with no such field.
  assert.equal(supportsRemoteUsers(wrapped, "cockroach"), true);
  assert.equal(supportsRemoteUsers({ engines: [{ engine: "mysql" }] }, "mysql"), true);
});

test("the fetcher still wraps its list — the assumption this pins", () => {
  // If getEngines ever returns a bare array, `supportsRemoteUsers` already
  // copes; this exists so the change is noticed rather than silently relied on.
  const fetcher = readFileSync(
    new URL("../lib/databases/get-databases.js", import.meta.url),
    "utf8",
  );
  assert.match(fetcher, /return \{ engines: data\?\.engines \?\? \[\], failed \}/);

  // And the page must go through the helper, not dig into the shape inline.
  const page = readFileSync(
    new URL("../app/(app)/databases/[database]/page.jsx", import.meta.url),
    "utf8",
  );
  assert.match(page, /supportsRemoteUsers\(engines, data\.engine\)/);
  assert.doesNotMatch(page, /engines\.find\(/, "the shape is being guessed again");
});
