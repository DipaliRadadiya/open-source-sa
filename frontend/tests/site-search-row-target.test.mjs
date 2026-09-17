import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Reported: "after search anything in files if we click on anything it is not
 * opening any files or folders or images".
 *
 * Two separate faults behind one symptom, both measured in the harness before
 * anything was changed:
 *
 * 1. A folder result linked to `dirname(path)` — its PARENT — so clicking a
 *    hit opened the folder ABOVE the one clicked. The component's own docblock
 *    already claimed it navigated into the folder, so the comment and the code
 *    disagreed and the comment was right.
 *
 * 2. Only the name text responded. On a 1232x66 row the real target measured
 *    151x24 — about 4% of what looks clickable — so the icon, the folder line
 *    and all the empty space to the right were dead. That reads as "search is
 *    broken", not as "you missed".
 */

const read = (p) => fs.readFileSync(p, "utf8");
const source = read("components/applications/files/site-search-results.jsx");
// Negative assertions have to run against code, not the prose above them —
// this file's own comments name the very strings some of these forbid.
const code = source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

test("a folder result opens the folder, not its parent", () => {
  assert.match(code, /const openDirHref = `\/applications\/\$\{appId\}\/files\?path=\$\{encodeURIComponent\(file\.path\)\}`/);
  assert.match(code, /<Link href=\{openDirHref\}/);

  // The parent is still computed — it is the right answer for the "in <folder>"
  // line underneath — but it must no longer be what the name points at.
  assert.match(code, /const folderHref = `\/applications\/\$\{appId\}\/files\?path=\$\{encodeURIComponent\(folder\)\}`/);
  assert.doesNotMatch(code, /<Link href=\{folderHref\} className="block truncate/);
});

test("the whole row is the click target", () => {
  assert.match(code, /const STRETCH = "after:absolute after:inset-0/);

  // Both things a row can lead to — opening a file, entering a folder.
  const stretched = code.match(/STRETCH\)\}/g) ?? [];
  assert.equal(stretched.length, 2, "both the folder link and the file button span the row");
});

test("the folder link stays reachable above the row overlay", () => {
  // An <a> cannot be nested inside another <a>, which is why this is an
  // overlay on the name rather than a wrapper around the row — and why the
  // secondary link has to be lifted over it or the row would swallow it.
  assert.match(code, /className="relative z-10 hover:text-foreground hover:underline"/);
  assert.match(code, /className=\{cn\(\s*\n?\s*"relative flex items-center/);
});

test("rows that lead nowhere get no affordance", () => {
  // A .zip has nothing to open and no folder to enter: no overlay, and no
  // hover state promising one. Offering the click and then doing nothing is
  // the fault being fixed, so it must not be reintroduced for these rows.
  assert.match(code, /const openable = !symlink && !isDir && canOpenFile\(file\.name\)/);
  assert.match(code, /const interactive = isDir \|\| openable/);
  assert.match(code, /interactive &&\s*\n?\s*"transition-colors hover:bg-accent/);
  assert.match(code, /!openable \? \(/);
});
