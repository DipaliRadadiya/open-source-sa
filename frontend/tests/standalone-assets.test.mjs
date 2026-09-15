import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const pkg = JSON.parse(fs.readFileSync("package.json", "utf8"));
const config = fs.readFileSync("next.config.mjs", "utf8");

test("a standalone build copies public/ and .next/static into itself", () => {
  /*
   * `output: 'standalone'` traces the server's dependencies and nothing else,
   * so `.next/standalone/` ships without either directory. Next treats the
   * copy as a deployment step — which is fine until the deployment step is a
   * person.
   *
   * It shipped broken once: the panel had never served a file from `public/`,
   * so the omission was invisible until the first commit that added image
   * files. Every logo 404'd on a server whose build-and-restart had always
   * been enough, and the build was green.
   */
  assert.match(config, /output:\s*['"]standalone['"]/, "the premise of this test");
  /*
   * Chained onto `build`, NOT a `postbuild` hook. `npm run build
   * --ignore-scripts` skips lifecycle hooks without a word, and the documented
   * restart for this panel is `npm ci --ignore-scripts && npm run build` — so a
   * hook reproduces the silent failure it exists to prevent, on the very
   * command most likely to run it.
   */
  assert.match(
    pkg.scripts?.build ?? "",
    /next build && node scripts\/copy-standalone-assets\.mjs/,
    "the copy must be part of the build command, not a skippable hook",
  );
  assert.equal(pkg.scripts?.postbuild, undefined, "one mechanism, not two");
  assert.equal(fs.existsSync("scripts/copy-standalone-assets.mjs"), true);
});

test("the copy replaces rather than merges", () => {
  /*
   * A file deleted from `public` between builds would otherwise be served for
   * ever by a copy nobody cleaned up — the kind of stale asset that outlives
   * the reason anyone remembers for it.
   */
  const script = fs.readFileSync("scripts/copy-standalone-assets.mjs", "utf8");
  assert.match(script, /rmSync\(to, \{ recursive: true, force: true \}\)/);
});

test("it is a no-op when there is no standalone build", () => {
  // `next start` serves both directories itself; a dev build has nowhere to
  // copy to and must not fail the script.
  const script = fs.readFileSync("scripts/copy-standalone-assets.mjs", "utf8");
  assert.match(script, /existsSync\(standalone\)/);
  assert.match(script, /process\.exit\(0\)/);
});
