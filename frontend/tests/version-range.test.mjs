import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { compareVersions, versionWithin, versionsInRange } from "../lib/runtime/version-range.js";
import { preselectVersion } from "../lib/runtime/preselect-version.js";

const v = (...list) => list.map((version) => ({ version }));

// The real ranges, from GET /site-types.
const NODEBB = { min: "22", max: null };
const N8N = { min: "20.19", max: "24" };
const PRESTASHOP = { min: "7.2", max: "8.1" };

test("both ends are inclusive, as the backend compares them", () => {
  assert.equal(versionWithin("22", NODEBB), true, "the minimum itself must qualify");
  assert.equal(versionWithin("24", N8N), true, "the maximum itself must qualify");
  assert.equal(versionWithin("8.1", PRESTASHOP), true);
  assert.equal(versionWithin("21", NODEBB), false);
  assert.equal(versionWithin("25", N8N), false);
  assert.equal(versionWithin("8.2", PRESTASHOP), false);
});

test("a missing segment counts as zero", () => {
  // "22" and "22.0" are the same version; the n8n floor is the case that cares.
  assert.equal(compareVersions("22", "22.0"), 0);
  assert.equal(versionWithin("20.19", N8N), true, "the exact n8n floor was excluded");
  assert.equal(versionWithin("20.18", N8N), false);
  assert.equal(versionWithin("20", N8N), false, "20 is below 20.19");
  // 20.9 vs 20.19 is the comparison a string sort gets backwards.
  assert.equal(compareVersions("20.9", "20.19") < 0, true);
});

test("the reported bug: Node 20 is no longer offered for NodeBB", () => {
  const installed = v("24", "22", "20");
  const offered = versionsInRange(installed, NODEBB).map((x) => x.version);
  assert.deepEqual(offered, ["24", "22"]);
  assert.ok(!offered.includes("20"));
  // And the preselect can only land on something offerable.
  assert.ok(offered.includes(preselectVersion(versionsInRange(installed, NODEBB), null)));
});

test("no range means every installed version, untouched", () => {
  const installed = v("8.4", "8.3");
  assert.equal(versionsInRange(installed, null), installed);
  assert.equal(versionsInRange(installed, undefined), installed);
  assert.equal(versionsInRange(installed, { min: null, max: null }), installed);
});

test("an unsatisfiable range offers everything rather than nothing", () => {
  // Mirrors installedPhpVersionsInRange(): a select with no options explains
  // nothing, while a version the server refuses at least names the problem.
  const installed = v("8.4", "8.3");
  assert.deepEqual(versionsInRange(installed, PRESTASHOP), installed);
});

test("junk in never empties the dropdown or throws", () => {
  assert.deepEqual(versionsInRange(null, NODEBB), []);
  assert.deepEqual(versionsInRange(undefined, null), []);
  assert.equal(versionWithin(undefined, NODEBB), false);
  assert.equal(versionWithin("", NODEBB), false);
  // An unreadable version must not decide a field is unusable on its own.
  assert.equal(versionsInRange(v("lts", "22"), NODEBB).length, 1);
});

test("the form filters the pickers and drops a version the new type refuses", () => {
  const source = fs.readFileSync(
    new URL("../components/applications/create-application-form.jsx", import.meta.url),
    "utf8",
  );
  assert.match(source, /phpVersions=\{typePhpVersions\}/, "the PHP picker shows unsupported versions again");
  assert.match(source, /nodeVersions=\{typeNodeVersions\}/, "the Node picker shows unsupported versions again");
  assert.match(source, /preselectVersion\(typePhpVersions/, "the preselect can pick an unsupported version");
  assert.match(source, /versionWithin\(current, range\)/, "a switch no longer clears an unsupported version");
  // The clear must be read before the two blocks that refill an empty field,
  // or the field is emptied and never filled again.
  assert.ok(
    source.indexOf("lastType.current = selected") <
      source.indexOf("preselectVersion(typePhpVersions"),
    "the type-change reset runs after the preselect, so a cleared version stays empty",
  );
});
