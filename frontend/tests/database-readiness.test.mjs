import test from "node:test";
import assert from "node:assert/strict";
import { engineInstalling, noDatabaseEngine } from "../lib/applications/database-readiness.js";

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
