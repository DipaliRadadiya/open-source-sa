import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Reported twice: "clicking a search result does nothing."
 *
 * It did something. The URL changed and the breadcrumb changed — the reporter's
 * screenshot shows `Site root › … › plugins › wordpress` sitting above the very
 * search results he had just clicked out of. What did not change was the panel,
 * because moving between folders is a client-side transition into the SAME
 * component instance and every piece of its state survived.
 *
 * Measured in the preview harness with the real FilesPanel, before and after:
 *
 *   without the key   path → new folder | searchBox "wp-" | listing []
 *   with the key      path → new folder | searchBox ""    | listing [plugin.js, …]
 *
 * An unchanged list under a changed breadcrumb is indistinguishable from a dead
 * link, which is exactly how it was reported — twice, because the first fix
 * addressed where the links POINTED and never asked what happened after you
 * followed one.
 */

const page = fs.readFileSync("app/(app)/applications/[application]/files/page.jsx", "utf8");
const panel = fs.readFileSync("components/applications/files/files-panel.jsx", "utf8");

test("the panel is remounted when the folder changes", () => {
  assert.match(page, /<FilesPanel\s*\n\s*key=\{filesResult\.path\}/);
});

test("all of the panel's state is per-folder, which is why a key is the fix", () => {
  /*
   * A key rather than an effect per field. Each of these is about the folder
   * you are looking at and none of them should outlive it — and an effect list
   * is a thing to forget to extend when the next one is added.
   */
  for (const field of ["query", "siteSearch", "selected", "highlightPath", "action"]) {
    assert.match(panel, new RegExp(`\\[${field}, set`), `${field} should still be local state`);
  }
});

test("toggling hidden files does NOT remount", () => {
  /*
   * It is the same folder, so a selection has to survive it. `showHidden` is a
   * separate search param and deliberately not part of the key — keying on the
   * whole query string would drop a selection every time someone pressed
   * "Show hidden files".
   */
  assert.doesNotMatch(page, /key=\{[^}]*showHidden/);
  assert.doesNotMatch(page, /key=\{[^}]*searchParams/);
});

test("the search results still link a folder to itself", () => {
  // The first fix, which was correct as far as it went: a folder result used to
  // link to its PARENT. Kept here so the two halves cannot regress apart.
  const results = fs.readFileSync("components/applications/files/site-search-results.jsx", "utf8");
  assert.match(results, /const openDirHref = `\/applications\/\$\{appId\}\/files\?path=\$\{encodeURIComponent\(file\.path\)\}`/);
  assert.match(results, /<Link href=\{openDirHref\}/);
});
