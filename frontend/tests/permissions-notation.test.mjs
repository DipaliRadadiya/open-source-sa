import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { symbolicMode } from "../lib/files/describe-mode.js";

/*
 * Reported as "the Permissions column and the three-dot menu disagree".
 *
 * They never did — both read `file.mode` off the same row. The column wrote it
 * as `-rw-r--r--` and the dialog as `644`, and nothing on either screen said
 * those were one value in two notations, so reading them as a contradiction
 * was fair. Both now name both.
 *
 * Verified on screen before changing anything: four modes, column vs dialog,
 * every pair agreed.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const table = read("components/applications/files/files-table.jsx");
const dialog = read("components/applications/files/permissions-dialog.jsx");

const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);

test("the two notations really are the same value", () => {
  // The claim the whole change rests on. If these ever diverge, the report was
  // right and this test is where that surfaces.
  assert.equal(symbolicMode("644", "file"), "-rw-r--r--");
  assert.equal(symbolicMode("755", "dir"), "drwxr-xr-x");
  assert.equal(symbolicMode("600", "file"), "-rw-------");
  // Four digits: sticky world-writable, what an uploads folder usually is.
  assert.equal(symbolicMode("1777", "dir"), "drwxrwxrwt");
  // Not a mode at all — the column falls back to printing the raw value.
  assert.equal(symbolicMode("nonsense", "file"), null);
});

test("the column shows the octal, not only on hover", () => {
  assert.match(table, /\{symbolic \? <span className="text-muted-foreground\/70">\{file\.mode\}<\/span> : null\}/);
  // Both lines come off one field. A second source is exactly what was
  // reported, and must not become true.
  assert.equal(table.match(/file\.mode/g).length >= 2, true);
});

test("the column stacks the two rather than widening", () => {
  // Measured with the sidebar present: side by side overflowed this column by
  // 40px at 1024, 21px at 1152 and 2px at 1280, and the table renders from
  // 1024 up. Two lines need only the longer string's width.
  assert.match(table, /flex flex-col gap-0\.5 whitespace-nowrap font-mono text-xs/);
});

test("the dialog names both too", () => {
  assert.match(dialog, /import \{ modeParts, symbolicMode \}/);
  assert.match(dialog, /symbolicMode\(currentMode, file\.type\)\s*\n?\s*\? "permissionsDialog\.subtitleWithCurrentBoth"/);
  // The one-notation string stays for a mode that has no symbolic form —
  // printing "Currently 999 ()" would be worse than the old wording.
  assert.match(dialog, /: "permissionsDialog\.subtitleWithCurrent"/);
});

test("both subtitle strings exist in every locale", () => {
  for (const locale of LOCALES) {
    const d = JSON.parse(read(`messages/${locale}.json`)).applications.files.permissionsDialog;
    assert.ok(d.subtitleWithCurrent, `${locale}: subtitleWithCurrent missing`);
    const both = d.subtitleWithCurrentBoth;
    assert.ok(both, `${locale}: subtitleWithCurrentBoth missing`);
    for (const token of ["{mode}", "{symbolic}"]) {
      assert.ok(both.includes(token), `${locale}: subtitleWithCurrentBoth needs ${token}`);
    }
  }
});
