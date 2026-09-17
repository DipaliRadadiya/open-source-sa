import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { sharedMode, selectedFiles } from "../lib/files/shared-mode.js";

/*
 * Reported: selecting one file and pressing the Permissions button in the
 * selection bar showed a different permission from the same file's three-dot
 * Permissions.
 *
 * It did. The bulk dialog was `useState("644")` — a constant, never the
 * selection. Picking the 755 folder `wp-admin` opened on 644 with "Read-only,
 * the usual choice for files" pre-selected, over something that is not a file.
 * 644 on a directory clears the execute bit, and a directory without execute
 * cannot be opened: Save was one click from taking the WordPress admin down.
 *
 * This is the guard on both halves — that the seed comes from the selection,
 * and that a mixed selection is told apart from a known one rather than having
 * one file's mode presented as everyone's.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const bulk = read("components/applications/files/bulk-dialogs.jsx");
const panel = read("components/applications/files/files-panel.jsx");
const code = bulk.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);

test("one selected item reports its own mode", () => {
  assert.equal(sharedMode([{ mode: "755" }]), "755");
  assert.equal(sharedMode([{ mode: "1777" }]), "1777");
});

test("agreeing items report the shared mode, disagreeing ones report none", () => {
  assert.equal(sharedMode([{ mode: "644" }, { mode: "644" }]), "644");
  assert.equal(sharedMode([{ mode: "644" }, { mode: "755" }]), null);
});

test("an unknown mode is not treated as agreement", () => {
  // A row the listing sent no mode for makes the answer unknown, not "the
  // others agree" — claiming a current value is the whole failure here.
  assert.equal(sharedMode([{ mode: "644" }, {}]), null);
  assert.equal(sharedMode([{ mode: "644" }, { mode: null }]), null);
  assert.equal(sharedMode([]), null);
});

test("only the checked rows are considered", () => {
  const files = [
    { path: "a.txt", mode: "644" },
    { path: "b.sh", mode: "755" },
    { path: "c.txt", mode: "600" },
  ];
  assert.deepEqual(
    selectedFiles(files, ["a.txt", "c.txt"]).map((f) => f.path),
    ["a.txt", "c.txt"],
  );
  assert.equal(sharedMode(selectedFiles(files, ["b.sh"])), "755");
  assert.equal(sharedMode(selectedFiles(files, ["a.txt", "b.sh"])), null);
});

test("the dialog seeds from the selection, not from a constant", () => {
  assert.match(code, /const currentMode = sharedMode\(chosen\)/);
  assert.match(code, /useState\(\(\) => currentMode \?\? ""\)/);
  // The bug, exactly as it was written.
  assert.doesNotMatch(code, /useState\("644"\)/);

  // It cannot seed from anything without the rows, so the panel must hand
  // them over — paths alone are what made the constant necessary.
  assert.match(panel, /files=\{files\}/);
  assert.match(code, /selectedFiles\(files, paths\)/);
});

test("a mixed selection is not given someone else's mode as its current", () => {
  assert.match(code, /currentMode\s*\n?\s*\? t\("bulk\.permissionsDescriptionCurrent"/);
  assert.match(code, /: t\("bulk\.permissionsDescriptionMixed"\)/);
});

test("a mixed selection starts empty and cannot be saved untouched", () => {
  /*
   * The same fault as the constant, one level over: the fallback no longer
   * CLAIMED to be current, but it was still pre-filled and one click from
   * being applied. Selecting a 644 file and a 600 one and pressing Save
   * without touching anything sent 644 for both — making a secrets file
   * readable by every account on the box, on a value nobody chose.
   */
  assert.match(code, /const mustChooseMode = action === "permissions" && !currentMode/);
  assert.match(code, /useState\(\(\) => currentMode \?\? ""\)/);
  // Seeding the fallback is what made the hazard reachable.
  assert.doesNotMatch(code, /useState\(\(\) => currentMode \?\? DEFAULT_MODE\)/);

  // Save refuses an unchosen mode, and says why rather than sitting dead.
  assert.match(code, /disabled=\{busy \|\| \(isPermissions \? !mode : !target\.trim\(\)\)\}/);
  assert.match(code, /mustChooseMode && !mode\s*\n?\s*\? t\("bulk\.permissionsChooseMode"\)/);
});

test("both descriptions exist in every locale", () => {
  for (const locale of LOCALES) {
    const b = JSON.parse(read(`messages/${locale}.json`)).applications.files.bulk;
    const current = b.permissionsDescriptionCurrent;
    assert.ok(current, `${locale}: permissionsDescriptionCurrent missing`);
    for (const token of ["{mode}", "{symbolic}"]) {
      assert.ok(current.includes(token), `${locale}: needs ${token}`);
    }
    const mixed = b.permissionsDescriptionMixed;
    assert.ok(mixed, `${locale}: permissionsDescriptionMixed missing`);
    // The mixed case has no single value to name; a placeholder here would
    // mean someone reintroduced one.
    assert.doesNotMatch(mixed, /\{mode\}|\{symbolic\}/, `${locale}: mixed must name no mode`);

    assert.ok(b.permissionsChooseMode, `${locale}: permissionsChooseMode missing`);
  }
});
