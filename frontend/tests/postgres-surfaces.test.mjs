import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { activeQueries, isIdle, isBackgroundWorker, slowQueryRate } from "../lib/databases/health.js";

/*
 * Fixtures captured from a real PostgreSQL 16.15 server on 2026-09-11, through
 * `GET /databases/processes?engine=postgresql`. Eleven rows, and not one of
 * them a running query: six parked clients and five of PostgreSQL's own
 * background workers. The panel listed all eleven as work.
 */
const PG_PROCESSES = [
  { id: "61039", user: "nodebb_pg", host: "127.0.0.1/32", db: "nodebb_pg", command: "idle", time: 6, state: "Client", query: "COMMIT" },
  { id: "1329", user: "nodebb_pg", host: "127.0.0.1/32", db: "nodebb_pg", command: "idle", time: 0, state: "Client", query: null },
  { id: "951", user: "postgres", host: "local", db: null, command: "", time: 0, state: "Activity", query: null },
  { id: "950", user: "", host: "local", db: null, command: "", time: 0, state: "Activity", query: null },
  { id: "937", user: "", host: "local", db: null, command: "", time: 0, state: "Activity", query: null },
  // The panel's own monitoring connection — a real query, and the one row that
  // genuinely belongs in the list.
  { id: "4242", user: "panel_sdiulnzek9", host: "127.0.0.1/32", db: "postgres", command: "active", time: 0, state: "active", query: "SELECT count(*) FROM pg_stat_activity" },
];

// MySQL's vocabulary, which is all this code understood before.
const MYSQL_PROCESSES = [
  { id: "1", user: "wp", host: "localhost", db: "wp", command: "Sleep", time: 40, state: "", query: null },
  { id: "2", user: "wp", host: "localhost", db: "wp", command: "Query", time: 3, state: "Sending data", query: "SELECT 1" },
];

test("an idle connection is idle in either engine's words", () => {
  // `Sleep` is MySQL's, `idle` is PostgreSQL's. Only the first was known.
  assert.equal(isIdle({ command: "Sleep" }), true);
  assert.equal(isIdle({ command: "idle" }), true);
  assert.equal(isIdle({ command: "Idle" }), true, "the engine's casing is not ours to rely on");
  assert.equal(isIdle({ command: "Query" }), false);
  assert.equal(isIdle({ command: "active" }), false);
  assert.equal(isIdle({}), false);
});

test("PostgreSQL's background workers are not connections anyone made", () => {
  /*
   * checkpointer, walwriter, autovacuum launcher — `pg_stat_activity` lists
   * them beside real clients with no user, no database and no statement. Each
   * one rendered a row offering "Stop query" for a query that does not exist.
   */
  assert.equal(isBackgroundWorker({ user: "", db: null, query: null }), true);
  // The autovacuum launcher runs as `postgres` — having a user proves nothing.
  assert.equal(isBackgroundWorker({ user: "postgres", db: null, query: null }), true);
  assert.equal(isBackgroundWorker({ user: "wp", db: "wp", query: "SELECT 1" }), false);
  // A real client between statements has a database, so it is idle, not internal.
  assert.equal(isBackgroundWorker({ user: "nodebb_pg", db: "nodebb_pg", query: null }), false);
});

test("the running list on a quiet PostgreSQL server holds one row, not eleven", () => {
  const running = activeQueries(PG_PROCESSES);
  assert.deepEqual(
    running.map((p) => p.id),
    ["4242"],
    "idle clients and background workers are being counted as work",
  );
});

test("MySQL keeps behaving exactly as it did", () => {
  // The fix must not narrow the engine it already got right.
  const running = activeQueries(MYSQL_PROCESSES);
  assert.deepEqual(running.map((p) => p.id), ["2"]);
  assert.equal(MYSQL_PROCESSES.filter(isIdle).length, 1);
});

test("the list and the headline read the same rule", () => {
  /*
   * The stat cards call `activeQueries`; the list under them used its own copy
   * of `command !== "sleep"`. That is how the card said "1 running" while the
   * list beneath it said "8 running" on the same data.
   */
  const list = readFileSync(new URL("../components/databases/process-list.jsx", import.meta.url), "utf8");
  assert.match(list, /activeQueries\(all\)/);
  assert.match(list, /all\.filter\(isIdle\)/);
  assert.doesNotMatch(list, /toLowerCase\(\) !== "sleep"/, "the second copy is back");
});

test("no slow-query card when the engine does not report slow queries", () => {
  // PostgreSQL sends `slow_queries: null`. `Number(null)` is 0 and finite, so
  // without the guard an unmeasured counter reads as the best possible news.
  assert.equal(slowQueryRate({ slow_queries: null, uptime_seconds: 26509 }), null);
  assert.equal(slowQueryRate({ slow_queries: 0, uptime_seconds: 3600 }), 0);
});

test("an engine with no host on its accounts is not offered remote access", () => {
  /*
   * `SupportsRemoteDatabaseUsers` guards three requests — create-database-with-
   * user, create-user and update-user. Only the first dialog honoured it, so
   * the same refused choice was still one click away on the same database.
   */
  const fields = readFileSync(new URL("../components/databases/user-fields.jsx", import.meta.url), "utf8");
  assert.match(fields, /remoteUsers = true/, "an older API must keep the choice");
  assert.match(fields, /\.\.\.\(remoteUsers/);

  for (const file of ["add-user-dialog.jsx", "edit-user-dialog.jsx"]) {
    const source = readFileSync(new URL(`../components/databases/${file}`, import.meta.url), "utf8");
    assert.match(source, /remoteUsers = true/, `${file} does not accept the fact`);
    assert.match(source, /remoteUsers=\{remoteUsers\}/, `${file} does not pass it on`);
  }

  // And the page has to look it up, or the prop is a default that never moves.
  const page = readFileSync(
    new URL("../app/(app)/databases/[database]/page.jsx", import.meta.url),
    "utf8",
  );
  // The lookup lives in lib/databases/engine-capabilities.js now — it is
  // behaviour, and an inline expression here could not be tested. It was not,
  // and it shipped calling `.find()` on an object.
  assert.match(page, /supportsRemoteUsers\(engines, data\.engine\)/);
  assert.match(page, /getEngines\(\)\.catch/, "a failed lookup must not take the page down");
});

test("the connection dialog suggests the engine's own port", () => {
  const dialog = readFileSync(
    new URL("../components/databases/connection-dialog.jsx", import.meta.url),
    "utf8",
  );
  assert.match(dialog, /placeholder=\{String\(DEFAULT_PORT\[engine\?\.engine\] \?\? 3306\)\}/);
  assert.doesNotMatch(dialog, /placeholder="3306"/, "MySQL's port, on a PostgreSQL form");
});
