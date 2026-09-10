import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import {
  compareVersions,
  rangeLabel,
  rangeUnsatisfied,
  versionWithin,
  versionsInRange,
} from "../lib/runtime/version-range.js";
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

test("an unsatisfiable range offers NOTHING, never a version that will be refused", () => {
  // This used to return the whole list, which is what made the filter look
  // broken on a server with only PHP 8.4: PrestaShop wants 7.2–8.1, nothing
  // qualified, and the picker offered 8.4 anyway.
  const installed = v("8.4", "8.3");
  assert.deepEqual(versionsInRange(installed, PRESTASHOP), []);
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


// --- The block a server with no usable version has to show ---

test("an unsatisfiable range is reported, so the picker can say so before the click", () => {
  assert.equal(rangeUnsatisfied(v("8.4"), PRESTASHOP), true, "8.4 against 7.2–8.1");
  // Version lists and ranges are paired by the caller; this only compares
  // numbers, so it is never told which runtime it is looking at.
  assert.equal(rangeUnsatisfied(v("20", "21"), NODEBB), true, "no installed Node reaches 22");
});

test("no range and no runtime are both 'not this problem'", () => {
  assert.equal(rangeUnsatisfied(v("8.4"), null), false, "most types run on anything installed");
  assert.equal(rangeUnsatisfied(v("8.4"), { min: null, max: null }), false);
  assert.equal(
    rangeUnsatisfied([], PRESTASHOP),
    false,
    "nothing installed is a different problem with a different fix, reported elsewhere",
  );
  assert.equal(rangeUnsatisfied(null, PRESTASHOP), false);
});

test("one usable version is enough", () => {
  assert.equal(rangeUnsatisfied(v("8.4", "8.0"), PRESTASHOP), false);
  assert.equal(rangeUnsatisfied(v("20", "22"), NODEBB), false);
  assert.equal(rangeUnsatisfied(v("20", "21"), NODEBB), true);
});

test("the range reads the way the backend says it", () => {
  assert.equal(rangeLabel(PRESTASHOP), "7.2 – 8.1");
  assert.equal(rangeLabel(NODEBB), "22+");
  assert.equal(rangeLabel({ min: null, max: "8.1" }), "≤ 8.1");
  assert.equal(rangeLabel(null), "");
});

test("a blocked type is marked the way the picker already greys them", async () => {
  const { runtimeBlock, withRuntimeAvailability } = await import(
    "../lib/applications/runtime-readiness.js"
  );

  const prestashop = { name: "prestashop", available: true, php_version_range: PRESTASHOP };
  const wordpress = { name: "wordpress", available: true, php_version_range: null };
  const runtimes = { phpVersions: v("8.4"), nodeVersions: v("24") };

  const block = runtimeBlock({ type: prestashop, ...runtimes });
  assert.equal(block.runtime, "php");
  assert.equal(block.label, "7.2 – 8.1");
  assert.deepEqual(block.installed, ["8.4"]);
  assert.equal(runtimeBlock({ type: wordpress, ...runtimes }), null);

  const marked = withRuntimeAvailability([prestashop, wordpress], runtimes, () => "reason");
  assert.equal(marked[0].available, false);
  assert.equal(marked[0].unavailable_code, "runtime");
  assert.equal(marked[0].unavailable_reason, "reason");
  assert.equal(
    marked[0].installable_runtime,
    null,
    "PHP is installed — the fix is another version of it, not the runtime",
  );
  assert.equal(marked[1], wordpress, "an unaffected type is returned untouched");
});

test("a failed runtime lookup greys nothing", async () => {
  const { runtimeBlock } = await import("../lib/applications/runtime-readiness.js");
  const type = { name: "prestashop", available: true, php_version_range: PRESTASHOP };
  assert.equal(
    runtimeBlock({ type, phpVersions: v("8.4"), failed: true }),
    null,
    "one endpoint's wobble must not empty the catalogue",
  );
});

test("a type the backend already blocked keeps the backend's reason", async () => {
  const { runtimeBlock } = await import("../lib/applications/runtime-readiness.js");
  const type = { name: "prestashop", available: false, php_version_range: PRESTASHOP };
  assert.equal(runtimeBlock({ type, phpVersions: v("8.4") }), null);
});

test("the node range blocks on node versions, never on php ones", async () => {
  const { runtimeBlock } = await import("../lib/applications/runtime-readiness.js");
  const nodebb = { name: "nodebb", available: true, node_version_range: NODEBB };
  assert.equal(runtimeBlock({ type: nodebb, phpVersions: v("8.4"), nodeVersions: v("20") }).runtime, "node");
  assert.equal(runtimeBlock({ type: nodebb, phpVersions: v("8.4"), nodeVersions: v("24") }), null);
});

// --- The same bug on the site's own PHP screen ---

test("the per-site PHP screen warns when the chosen version is out of range", async () => {
  const fs = await import("node:fs");
  const panel = fs.readFileSync("components/applications/php/php-panel.jsx", "utf8");

  // Both modes: the shared-mode switcher and the isolated form's field.
  assert.equal(
    (panel.match(/versionUnsupported/g) ?? []).length,
    2,
    "shared and dedicated both change the version, so both have to say it",
  );
  assert.match(
    panel,
    /!versionWithin\(version, phpRange\)/,
    "the warning is derived from the range, never from a list of type names",
  );
});

test("the PHP page reads the range from the catalogue and survives losing it", async () => {
  const fs = await import("node:fs");
  const page = fs.readFileSync("app/(app)/applications/[application]/php/page.jsx", "utf8");
  assert.match(page, /getSiteTypes\(\)\.catch/, "the warning is a nicety; the page is not");
  assert.match(page, /type\.name === application\.site_type/);
  assert.match(page, /siteTypeTitle=/, "the PHP payload carries no site type, so the page passes it");
});

test("a runtime-blocked row carries its own way out, beside the reason", async () => {
  const fs = await import("node:fs");
  const picker = fs.readFileSync("components/applications/site-type-picker.jsx", "utf8");

  assert.match(picker, /type\?\.unavailable_code !== "runtime"/, "only types blocked BY a runtime");
  assert.match(picker, /href: "\/php", label: "form\.installPhpVersion"/);
  assert.match(picker, /href: "\/node", label: "form\.installNodeVersion"/);

  // The link lives with the sentence, not in a footer under the list.
  const reasonBlock = picker.slice(picker.indexOf("type.unavailable_reason ?"));
  assert.match(reasonBlock.slice(0, 900), /runtimeFix\(type\)/, "the link renders inside the reason");

  // An unavailable row cannot be a disabled button, or the link inside it is
  // unreachable — by a mouse, by a screen reader, and by Playwright.
  assert.match(picker, /const Row = disabled \? "div" : "button"/);
  assert.doesNotMatch(picker, /"aria-disabled": true/, "it takes the nested link down with it");

  // And the row must not dim wholesale: `opacity-60` on the row faded the
  // link too, so the one clickable thing looked as switched off as the rest.
  assert.doesNotMatch(
    picker,
    /disabled && "opacity-60 hover:bg-transparent/,
    "fade the choice — icon, name, tagline — never the reason or its link",
  );

  for (const locale of ["en", "es", "hi"]) {
    const messages = JSON.parse(fs.readFileSync(`messages/${locale}.json`, "utf8")).applications;
    assert.ok(messages.form.installPhpVersion, `${locale} missing installPhpVersion`);
    assert.ok(messages.form.installNodeVersion, `${locale} missing installNodeVersion`);
    // The instruction moved into the link, so the sentence must not repeat
    // it. Matched on the clause that was removed rather than the word
    // "install", which the `{installed}` placeholder contains.
    assert.doesNotMatch(
      messages.unavailableRuntime.php,
      /version first/i,
      `${locale} reason still gives the instruction the link now carries`,
    );
  }
});

test("the irreversible engine choice says so, keyed on the field not the engine", async () => {
  const fs = await import("node:fs");
  const form = fs.readFileSync("components/applications/create-application-form.jsx", "utf8");

  assert.match(form, /config\.name === "database_engine"/, "the API names the field; we do not name engines");
  assert.match(form, /form\.databaseEnginePermanent/);

  for (const locale of ["en", "es", "hi"]) {
    const messages = JSON.parse(fs.readFileSync(`messages/${locale}.json`, "utf8"));
    assert.ok(messages.applications.form.databaseEnginePermanent, `${locale} missing the warning`);
  }
});

test("a failed PostgreSQL install lands on the databases page like the others", async () => {
  const { installHome } = await import("../lib/services/install-home.js");
  for (const key of ["mysql", "mariadb", "mongodb", "postgresql"]) {
    assert.equal(installHome(key)?.href, "/databases", `${key} has a screen that handles its failure`);
  }
});
