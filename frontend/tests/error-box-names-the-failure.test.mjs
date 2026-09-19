import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { execFileSync } from "node:child_process";

/*
 * Krishna, on a red box on the Restores tab: "why getting this error?"
 *
 * I could not tell him. `LoadFailed` was built to answer exactly that — it
 * takes `status` and `failure` and turns them into "You do not have
 * permission" or "Error 500" — and 39 of the panel's 65 call sites passed
 * neither, so it rendered a shrug with no code. The panel knew which of the
 * six things had happened and threw it away at the last step.
 *
 * Every fetcher has carried both fields since `read()` was consolidated. The
 * gap was always at the call site, which is why a guard is the fix and not a
 * one-off sweep.
 */

const read = (p) => fs.readFileSync(p, "utf8");

test("no error box in the panel says nothing", () => {
  const pkg = JSON.parse(read("package.json"));
  assert.match(pkg.scripts.lint, /check-load-failed\.mjs/, "not in the lint chain");
  const out = execFileSync("node", ["scripts/check-load-failed.mjs"], { encoding: "utf8" });
  assert.match(out, /load-failed ok/);
  // The count is asserted so deleting the boxes is not a way to pass.
  assert.match(out, /\d+ error boxes/);
});

test("the guard masks comments rather than stripping them", () => {
  // Third time in this repo: a check reading a docblock's own example as a
  // fresh offence. The line numbers have to stay true as well.
  assert.match(read("scripts/check-load-failed.mjs"), /const mask =/);
});

test("the three hand-rolled fetchers go through read() now", () => {
  /*
   * `getDoctor`, `getPanelUpdate`, `getSetup` and `getTrash` predated the
   * shared helper and each had its own try/catch returning one bare `null` (or
   * one `FAILED` constant) for a 403, a 500, a dead request and a shape
   * mismatch alike. Nothing reached the journal either, because `report()`
   * never ran — so those screens could not explain a failure even in the logs.
   */
  for (const file of [
    "lib/admin/get-doctor.js",
    "lib/admin/get-panel-update.js",
    "lib/setup/get-setup.js",
    "lib/applications/get-trash.js",
  ]) {
    const source = read(file);
    assert.match(source, /from "@\/lib\/api\/read"/, `${file} should use read()`);
    assert.match(source, /failed, status, failure/, `${file} should carry the diagnosis`);
    assert.doesNotMatch(source, /catch \{\s*return null;/, `${file} still swallows`);
  }
});

test("the firewall rules list carries its status too", () => {
  // It stopped at `failed`, so the rules card — the one that says what is and
  // is not being blocked — could never name a permission failure.
  assert.match(read("lib/firewall/get-firewall.js"), /status: result\.status/);
});

test("a box gets the status of the read it is reporting on", () => {
  /*
   * The bulk pass got seven of these wrong by handing every second box on an
   * application page the status of the APPLICATION read, which had succeeded.
   * A box that reports the wrong request's status is worse than a blank one:
   * it names a failure that did not happen. Only reading the guard beside each
   * box catches this, so the pairs are pinned here.
   */
  const pairs = [
    ["app/(app)/applications/[application]/domains/page.jsx", "domainList.failed", "domainList.status"],
    ["app/(app)/applications/[application]/php/page.jsx", "phpResult.failed", "phpResult.status"],
    ["app/(app)/applications/[application]/files/page.jsx", "filesResult.failed", "filesResult.status"],
    ["app/(app)/applications/[application]/staging/page.jsx", "staging.failed", "staging.status"],
    ["app/(app)/applications/[application]/fail2ban/page.jsx", "status.failed", "status.status"],
    ["app/(app)/applications/[application]/workers/page.jsx", "workersResult.failed", "workersResult.status"],
    ["app/(app)/applications/[application]/environment/page.jsx", "envResult.failed", "envResult.status"],
    ["app/(app)/firewall/page.jsx", "rulesFailed", "rulesStatus"],
    ["app/(app)/databases/page.jsx", "dbFailed", "dbStatus"],
  ];
  // Comments MASKED, not stripped, so the line numbers still line up — this
  // test found `domainList.failed` inside a docblock explaining the fix and
  // reported the fix as missing. The fourth time that trap has bitten here.
  const mask = (s) =>
    s
      .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, " "))
      .replace(/^[ \t]*\/\/.*$/gm, (m) => " ".repeat(m.length));

  for (const [file, guard, source] of pairs) {
    const lines = mask(read(file)).split("\n");
    // Anchor on the BOX and look back for its guard — anchoring on the guard
    // finds the destructure that introduced the name, which is nowhere near.
    const boxes = lines
      .map((line, i) => (line.includes("<LoadFailed") ? i : -1))
      .filter((i) => i !== -1);
    assert.ok(boxes.length, `${file}: no error box at all`);

    const guarded = boxes.filter((i) => lines.slice(Math.max(0, i - 4), i + 1).join(" ").includes(guard));
    assert.equal(guarded.length, 1, `${file}: expected one box guarded by ${guard}`);

    const box = lines.slice(guarded[0], guarded[0] + 6).join(" ");
    assert.ok(box.includes(source), `${file}: the ${guard} box should report ${source}`);
  }
});
