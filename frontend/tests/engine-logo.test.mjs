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
   * `pgsql` is PostgreSQL's DRIVER, not its engine name — the engine is
   * `postgresql` (config/server.php). Mapping the driver too would look
   * harmless and quietly hide the day the API starts sending something this
   * file has never heard of, which is exactly when the generic glyph is the
   * right answer.
   */
  assert.equal(engineLogo("pgsql"), null);
  assert.equal(engineLogo("cockroachdb"), null);
  assert.equal(engineLogo(""), null);
  assert.equal(engineLogo(null), null);
  assert.equal(engineLogo(undefined), null);
});

test("PostgreSQL uses one file for both themes, on purpose", () => {
  /*
   * The other three are wordmarks set in near-black and need a white twin.
   * PostgreSQL's official mark is the elephant alone, mid-blue, which reads on
   * a white card and a dark one alike — 86% of its ink measured lighter than
   * the dark surface. Identical paths here is the decision, not a copy-paste
   * slip, and the both-variants test below would otherwise look like it had
   * been satisfied by accident.
   */
  const pg = engineLogo("postgresql");
  assert.equal(pg.light, "/db-engines/postgresql.svg");
  assert.equal(pg.dark, pg.light);
  assert.ok(pg.size, "the elephant is square and needs its own height");
});

test("the engine name is matched however the API cases it", () => {
  assert.equal(engineLogo("MySQL").light, "/db-engines/mysql.svg");
  assert.equal(engineLogo("MariaDB").dark, "/db-engines/mariadb-white.png");
  assert.equal(engineLogo("PostgreSQL").light, "/db-engines/postgresql.svg");
});

test("no engine logo file is left unused", () => {
  const mapped = new Set(Object.values(ENGINE_LOGOS).flatMap((p) => [p.light, p.dark]));
  const orphans = fs.readdirSync("public/db-engines").filter((f) => !mapped.has(f));
  assert.deepEqual(orphans, [], `unused engine logos: ${orphans.join(", ")}`);
});

test("a dark variant keeps the light one's lockup", () => {
  /*
   * MySQL's supplied white file was a stacked mark — dolphin over the word,
   * 50×50 — while its light file is a 239×60 horizontal wordmark. The logo
   * therefore changed SHAPE when the theme changed, and needed its own height
   * to stay legible at all. The white file is now the horizontal one with its
   * single flat colour swapped.
   *
   * Compared as a RATIO, not as exact numbers: a variant may legitimately be
   * exported at a different scale, but not at a different shape.
   */
  const ratio = (file) => {
    const svg = fs.readFileSync(`public/db-engines/${file}`, "utf8").slice(0, 400);
    const w = Number(svg.match(/width="(\d+(?:\.\d+)?)/)?.[1]);
    const h = Number(svg.match(/height="(\d+(?:\.\d+)?)/)?.[1]);
    return w && h ? w / h : null;
  };
  for (const [engine, pair] of Object.entries(ENGINE_LOGOS)) {
    if (!pair.light.endsWith(".svg") || !pair.dark.endsWith(".svg")) continue;
    const [a, b] = [ratio(pair.light), ratio(pair.dark)];
    if (a === null || b === null) continue;
    assert.ok(
      Math.abs(a - b) / a < 0.05,
      `${engine}'s variants are different shapes: ${a.toFixed(2)} vs ${b.toFixed(2)}`,
    );
  }
});
