import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { ENGINE_LOGOS, engineLogo } from "../lib/databases/engine-logo.js";

test("both variants of every engine are on disk", () => {
  for (const [engine, pair] of Object.entries(ENGINE_LOGOS)) {
    for (const variant of ["light", "dark"]) {
      assert.equal(
        fs.existsSync(`public/db-engines/${pair[variant]}`),
        true,
        `${engine}'s ${variant} logo (${pair[variant]}) is missing`,
      );
    }
  }
});

test("every engine has BOTH variants, never just one", () => {
  /*
   * One variant is worse than none: these three brands set their name in
   * near-black, so a light-only entry renders a coloured shape with an
   * invisible word beside it on the dark theme — which reads as a rendering
   * bug rather than as a missing file.
   */
  for (const [engine, pair] of Object.entries(ENGINE_LOGOS)) {
    assert.ok(pair.light, `${engine} has no light variant`);
    assert.ok(pair.dark, `${engine} has no dark variant`);
  }
});

test("an engine we have no artwork for resolves to null, not a guessed path", () => {
  /*
   * PostgreSQL is the live case: the panel can run it and no logo was
   * supplied. Null is what gets it the generic database glyph instead of a
   * broken image.
   */
  assert.equal(engineLogo("postgresql"), null);
  assert.equal(engineLogo("pgsql"), null);
  assert.equal(engineLogo(""), null);
  assert.equal(engineLogo(null), null);
  assert.equal(engineLogo(undefined), null);
});

test("the engine name is matched however the API cases it", () => {
  assert.equal(engineLogo("MySQL").light, "/db-engines/mysql.svg");
  assert.equal(engineLogo("MariaDB").dark, "/db-engines/mariadb-white.png");
});

test("no engine logo file is left unused", () => {
  const mapped = new Set(Object.values(ENGINE_LOGOS).flatMap((p) => [p.light, p.dark]));
  const orphans = fs.readdirSync("public/db-engines").filter((f) => !mapped.has(f));
  assert.deepEqual(orphans, [], `unused engine logos: ${orphans.join(", ")}`);
});
