import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Reported: on Extract, the Path field does not say where the archive will go.
 *
 * The field is relative to the top of the site's folder, and at the root it
 * renders as an EMPTY box behind a "Site root" placeholder — which reads as an
 * unanswered question rather than as the answer it is. Someone about to unpack
 * an archive could not tell what they were overwriting or where.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const dialog = read("components/applications/files/target-path-dialog.jsx");

const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);

test("the destination is spelled out, and named even when it is the root", () => {
  // Routed through `destinationOf` so each dialog names its own part of the
  // path — the whole value for Extract, the folder for Compress.
  assert.match(dialog, /const trimmedTarget = destinationOf\(place\(value\.trim\(\)\)\)\.replace\(/);
  // Empty is the site's own root everywhere in this feature — it gets the
  // breadcrumb's word rather than rendering as nothing.
  assert.match(dialog, /: t\("root"\)/);
  assert.match(dialog, /trimmedTarget\.split\("\/"\)\.filter\(Boolean\)\.join\(" \/ "\)/);
  assert.match(dialog, /<CopyButton\s+value=\{destinationValue\}/);
});

test("it is opt-in, and on for the two dialogs that put something somewhere", () => {
  /*
   * Rename takes a new NAME for a thing; echoing it underneath says nothing.
   *
   * Compress was excluded on that same reasoning at first, and that was wrong:
   * its field is a full relative PATH, and the folder half of it decides where
   * the archive lands. Excluding it is what kept the capability invisible.
   */
  assert.match(dialog, /destinationLabel = null/);
  assert.match(read("components/applications/files/extract-dialog.jsx"), /destinationLabel=/);
  assert.match(read("components/applications/files/compress-dialog.jsx"), /destinationLabel=/);

  assert.doesNotMatch(
    read("components/applications/files/rename-dialog.jsx"),
    /destinationLabel=/,
    "rename echoes the name back at the user",
  );
});

test("each dialog names the right part of the path", () => {
  /*
   * Extract pours files INTO the path, so the whole value is the folder.
   * Compress writes one file AT the path, so the folder is the value minus the
   * filename — which is already on screen in the field, and repeating it would
   * say nothing.
   */
  const compress = read("components/applications/files/compress-dialog.jsx");
  // The folder part of the value — after a bare name is placed in the
  // file's own folder, so the line says where it really lands.
  assert.match(compress, /destinationOf=\{dirname\}/);
  // A bare name is placed in the item's folder once, in the shared dialog.
  assert.match(dialog, /const place = \(typed\) => placeTarget\(typed, file\.path, defaultTarget\);/);
  assert.doesNotMatch(
    read("components/applications/files/extract-dialog.jsx"),
    /destinationOf=/,
    "extract must treat the whole field as the folder",
  );
  assert.match(dialog, /destinationOf = \(value\) => value/);
});

test("compress no longer claims the archive lands next to its source", () => {
  // It never had to — the backend resolves the whole relative path — but the
  // subtitle said "next to it", which stopped being true the moment the folder
  // became visible and changeable.
  const en = JSON.parse(read("messages/en.json")).applications.files.compressDialog;
  assert.doesNotMatch(en.subtitle, /next to it/i);
  assert.ok(en.folderMustExist, "the must-already-exist warning is missing");
  assert.match(en.folderMustExist, /already exist/i);
});

test("nothing offers to copy an empty path", () => {
  // At the site root the path IS empty, and a button that puts an empty string
  // on the clipboard is a control that cannot do anything.
  assert.match(dialog, /\{destinationValue \? \(\s*<CopyButton/);
});

test("no absolute path is invented", () => {
  /*
   * The browser is rooted at `publicHtmlPath()` — the CODE root — and no field
   * the API sends is reliably equal to it. `document_root` is deeper whenever a
   * web root is set, and `path` (codePath) diverges for a non-git site with a
   * custom web root. Printing either would be a confident guess at a location,
   * which is worse than naming no location at all.
   */
  const code = dialog.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  assert.doesNotMatch(code, /document_root/);
  assert.doesNotMatch(code, /public_html/);
  assert.doesNotMatch(code, /application\.path/);
});

test("the warning about overwriting is still there", () => {
  // It was already right, and is the other half of the answer: where it lands,
  // and what that costs.
  const en = JSON.parse(read("messages/en.json")).applications.files.extractDialog;
  assert.match(en.warning, /overwrite/i);
  assert.match(en.warning, /no undo/i);
});

test("the new strings exist in every locale", () => {
  for (const l of LOCALES) {
    const td = JSON.parse(read(`messages/${l}.json`)).applications.files.targetDialog;
    assert.ok(td.destination, `${l} is missing targetDialog.destination`);
    assert.ok(td.copyDestination, `${l} is missing targetDialog.copyDestination`);
  }
});
