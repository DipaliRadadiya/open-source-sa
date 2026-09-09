import test from "node:test";
import fs from "node:fs";
import assert from "node:assert/strict";
import {
  acceptedEngines,
  databaseBlock,
  engineInstalling,
  noDatabaseEngine,
  withDatabaseAvailability,
} from "../lib/applications/database-readiness.js";

test("nothing installed is the only thing that warns", () => {
  assert.equal(noDatabaseEngine({ engines: [] }), true);
  assert.equal(
    noDatabaseEngine({ engines: [{ engine: "mariadb", installed: false, installable: true }] }),
    true,
  );
  assert.equal(noDatabaseEngine({ engines: [{ engine: "mariadb", installed: true }] }), false);
  // One of several is enough — a site needs an engine, not every engine.
  assert.equal(
    noDatabaseEngine({
      engines: [{ engine: "mongodb", installed: false }, { engine: "mariadb", installed: true }],
    }),
    false,
  );
});

test("installed, not running, is still installed", () => {
  /*
   * The schema is explicit that these are different questions: `running` means
   * we could connect with the configured credentials, `installed` means it is
   * on the server. A stopped MariaDB is a service to start, not a missing
   * engine — telling its owner to go and install one would send them to create
   * a second copy of something they already have.
   */
  assert.equal(
    noDatabaseEngine({ engines: [{ engine: "mariadb", installed: true, running: false }] }),
    false,
  );
});

test("a failed lookup says nothing", () => {
  // Not knowing is not the same as knowing there is none. A red line on the
  // create form every time one endpoint wobbles is worse than staying quiet.
  assert.equal(noDatabaseEngine({ engines: [], failed: true }), false);
  assert.equal(noDatabaseEngine({ failed: true }), false);
  assert.equal(noDatabaseEngine({}), false);
  assert.equal(noDatabaseEngine(), false);
  assert.equal(noDatabaseEngine({ engines: null }), false);
});

test("an engine mid-install is coming, not missing", () => {
  const engines = [{ engine: "mariadb", installed: false, install_status: "installing" }];
  // Still "no engine" for the strict question...
  assert.equal(noDatabaseEngine({ engines }), true);
  // ...but the caller can soften the message rather than sending someone back
  // to press Install a second time.
  assert.equal(engineInstalling({ engines }), true);
});

test("a failed install is not an install in progress", () => {
  const engines = [{ engine: "mariadb", installed: false, install_status: "failed" }];
  assert.equal(engineInstalling({ engines }), false);
  assert.equal(noDatabaseEngine({ engines }), true);
  assert.equal(engineInstalling(), false);
  assert.equal(engineInstalling({ engines: null }), false);
});

/*
 * The MongoDB-only server. The backend skips its own engine check for anything
 * accepting MySQL or MariaDB, so every database-backed type reported itself
 * available there and failed at provisioning with `no-database-engine` after
 * the form was filled in.
 */
const MONGO_ONLY = [
  { engine: "mysql", installed: false, running: false },
  { engine: "mariadb", installed: false, running: false },
  { engine: "mongodb", installed: true, running: true },
];
const wordpress = { name: "wordpress", needs_database: true, available: true };
const staticSite = { name: "static", needs_database: false, available: true };

test("a SQL type is blocked on a MongoDB-only server", () => {
  const block = databaseBlock({ type: wordpress, engines: MONGO_ONLY });
  assert.equal(block?.state, "missing");
  assert.deepEqual(block.engines, ["mysql", "mariadb"]);
});

test("every database-backed type is blocked, not just WordPress", () => {
  for (const name of ["wordpress", "joomla", "moodle", "prestashop", "craftcms"]) {
    const block = databaseBlock({
      type: { name, needs_database: true, available: true },
      engines: MONGO_ONLY,
    });
    assert.equal(block?.state, "missing", `${name} was left creatable`);
  }
});

test("a type that needs no database is never touched", () => {
  assert.equal(databaseBlock({ type: staticSite, engines: MONGO_ONLY }), null);
  assert.equal(databaseBlock({ type: staticSite, engines: [] }), null);
});

test("one usable SQL engine is enough", () => {
  const engines = [
    { engine: "mysql", installed: false, running: false },
    { engine: "mariadb", installed: true, running: true },
    { engine: "mongodb", installed: true, running: true },
  ];
  assert.equal(databaseBlock({ type: wordpress, engines }), null);
});

test("installed but unreachable is its own state, not 'install it'", () => {
  const engines = [{ engine: "mariadb", installed: true, running: false }];
  assert.equal(databaseBlock({ type: wordpress, engines })?.state, "stopped");
});

test("an install in progress says wait, not install", () => {
  const engines = [{ engine: "mysql", installed: false, install_status: "installing" }];
  assert.equal(databaseBlock({ type: wordpress, engines })?.state, "installing");
});

test("unknown never blocks the catalogue", () => {
  // A failed engine lookup says nothing about the server, and neither does an
  // empty list — greying every card on one endpoint's wobble is worse than the
  // failure this prevents.
  assert.equal(databaseBlock({ type: wordpress, engines: [], failed: true }), null);
  assert.equal(databaseBlock({ type: wordpress, engines: [] }), null);
});

test("a type the backend already blocked keeps the backend's reason", () => {
  // Two answers to one question is how they end up disagreeing. NodeBB on a
  // SQL-only server is the backend's call, and it stays the backend's call.
  const nodebb = {
    name: "nodebb",
    needs_database: true,
    available: false,
    unavailable_reason: "Needs MongoDB.",
    accepted_engines: ["mongodb"],
  };
  assert.equal(databaseBlock({ type: nodebb, engines: MONGO_ONLY }), null);
});

test("a declared engine list is preferred over the fallback", () => {
  // Absent field = an API that predates it, and only then does the SQL
  // fallback apply.
  assert.deepEqual(acceptedEngines({}), ["mysql", "mariadb"]);
  assert.deepEqual(acceptedEngines({ accepted_engines: ["mongodb"] }), ["mongodb"]);
  // The day the catalogue ships the field, a MongoDB type stops being blocked
  // by the SQL fallback without this file changing.
  const mongoType = { name: "nodebb", needs_database: true, available: true, accepted_engines: ["mongodb"] };
  assert.equal(databaseBlock({ type: mongoType, engines: MONGO_ONLY }), null);
});

test("the marked catalogue is the shape the picker already renders", () => {
  const marked = withDatabaseAvailability(
    [wordpress, staticSite],
    { engines: MONGO_ONLY },
    (block) => `blocked:${block.state}`,
  );

  assert.equal(marked[0].available, false);
  assert.equal(marked[0].unavailable_code, "database");
  assert.equal(marked[0].unavailable_reason, "blocked:missing");
  // The picker offers a runtime install off this field; a database block is
  // not one.
  assert.equal(marked[0].installable_runtime, null);
  // Untouched, same object contents.
  assert.deepEqual(marked[1], staticSite);
});

test("the picker's install link matches the code the page sets", () => {
  const source = fs.readFileSync(
    new URL("../components/applications/site-type-picker.jsx", import.meta.url),
    "utf8",
  );
  assert.match(
    source,
    /unavailable_code === "database"/,
    "the picker no longer branches on the code the API sends for a database block",
  );
});

test("an empty engine list is an answer, not a missing one", () => {
  /*
   * The catalogue ships `accepted_engines` now and sends `[]` for a type with
   * no installer — a custom PHP site brings its own arrangements, and the
   * backend's own check treats that as nothing to verify. Reading `[]` as
   * "fall back to SQL" would invent a requirement it does not have and grey
   * out a type the API is perfectly happy to create.
   */
  assert.equal(acceptedEngines({ accepted_engines: [] }), null);
  assert.equal(
    databaseBlock({
      type: { name: "php", needs_database: true, available: true, accepted_engines: [] },
      engines: MONGO_ONLY,
    }),
    null,
    "a type the catalogue names no engines for was blocked anyway",
  );
});
