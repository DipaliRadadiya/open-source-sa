import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { FILE_NAME } from "../lib/files/name-style.js";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

const thumb = read("components/applications/files/file-thumb.jsx");
const editor = read("components/applications/files/file-editor-dialog.jsx");
const restore = read("components/applications/files/restore-file-backup-dialog.jsx");
const upload = read("components/applications/files/upload-dialog.jsx");
const panel = read("components/applications/files/files-panel.jsx");
const tableSrc = read("components/applications/files/files-table.jsx");
const cards = read("components/applications/files/files-cards.jsx");
const api = read("lib/api/files.js");

test("thumbnails ask the preview endpoint, never download", () => {
  /*
   * `download` answers octet-stream + nosniff + attachment and is throttled at
   * 20/min for a few large transfers; an <img> will not draw an SVG from it at
   * all. In wp-admin/images 11 of 18 thumbnails were a broken-image glyph.
   * The route file says thumbnails belong on `preview`.
   */
  assert.match(api, /export function fileThumbnailUrl[\s\S]*?\/files\/preview\?path=/);
  assert.match(thumb, /src=\{fileThumbnailUrl\(appId, file\.path\)\}/);
  assert.doesNotMatch(thumb, /fileDownloadUrl/, "a thumbnail must not spend the download budget");
  assert.match(thumb, /loading="lazy"/, "rows nobody scrolled to must not ask");
});

test("an SVG gets the file icon, not a request the server always refuses", () => {
  // `preview` refuses SVG outright (it can carry script), so asking only ever
  // produced a broken image.
  assert.match(thumb, /const NO_THUMBNAIL = \/\\\.svgz\?\$\/i;/);
  assert.match(thumb, /isImageFile\(file\.name\) && !NO_THUMBNAIL\.test\(file\.name\)/);
});

test("a thumbnail that failed before hydration still falls back to the icon", () => {
  // The row is server-rendered, so a load can fail before React attaches
  // onError — the event is gone and the glyph stays. A finished image with no
  // width is a failure.
  assert.match(thumb, /img\?\.complete && img\.naturalWidth === 0/);
});

test("after a restore the editor re-reads the file instead of keeping the old text", () => {
  /*
   * The restore endpoint returns `{restored: true}` only. The editor waited for
   * a `file.content` that never came, kept the pre-restore text, and the next
   * Save wrote it straight back over the restore.
   */
  assert.match(editor, /async function reloadAfterRestore\(\)/);
  const fn = editor.slice(editor.indexOf("async function reloadAfterRestore"), editor.indexOf("useEffect(() => {"));
  assert.match(fn, /await getFileContent\(appId, file\.path\)/);
  assert.match(fn, /setContents\(parsed\.data\.content\)/);
  // Stale text beside a Save button is how the restore gets lost, so a failed
  // re-read closes the editor rather than leaving it open.
  assert.match(fn, /onOpenChange\?\.\(false\)/);
  assert.match(editor, /onRestored=\{\(\) => \{\s*reloadAfterRestore\(\);/);
  assert.doesNotMatch(restore, /data\?\.file/, "nothing may wait for content the endpoint never sends");
});

test("upload says what the server does with a name that is already taken", () => {
  const messages = fs.readdirSync(path.join(root, "messages")).filter((f) => f.endsWith(".json"));
  for (const file of messages) {
    const subtitle = JSON.parse(read(`messages/${file}`)).applications.files.uploadDialog.subtitle;
    // Every locale used to promise an overwrite; the server refuses instead.
    assert.doesNotMatch(
      subtitle,
      /overwrit|sobrescri|überschrieben|écrasé|अधिलेखित|上書き|перезаписан/i,
      `${file}: the subtitle still promises an overwrite`,
    );
  }
  // And a taken name is flagged when it is picked, from the listing already on
  // screen — not discovered after it has been sent.
  assert.match(panel, /existingNames=\{files\.map\(\(f\) => f\.name\)\}/);
  assert.match(upload, /const taken = existing\.has\(file\.name\);/);
  assert.match(upload, /error: taken \? t\("uploadDialog\.exists"\) : null/);
  assert.match(upload, /if \(item\.spaceBlocked \|\| item\.nameTaken\) \{/, "a taken name is never sent");
});

test("an upload in progress can be stopped", () => {
  // Cancel was disabled for the whole run; a large upload could not be ended
  // short of closing the tab.
  assert.match(upload, /signal: controller\.signal,/);
  assert.match(upload, /onClick=\{\(\) => abortRef\.current\?\.abort\(\)\}/);
  // Stopped is not failed: the file goes back to waiting, with no error.
  assert.match(upload, /if \(controller\.signal\.aborted\) \{\s*stopped = true;/);
});

test("a folder never shows the size of its own directory entry", () => {
  /*
   * The listing's `size_human` for a directory is the 4 KB of the entry itself.
   * Every folder read "4.0 KB" while the Storage panel put the same tree at
   * 100 MB. A folder shows a measured size or a dash.
   */
  assert.match(tableSrc, /const shown = file\.type === "dir" \? folderSizes\[file\.path\] : file\.size_human;/);
  assert.match(cards, /file\.type === "dir" \? folderSizes\[file\.path\] : file\.size_human/);
});

test("a name can reach its second line inside a nowrap table cell", () => {
  /*
   * The first version of the two-line name forgot that `TableCell` is
   * `whitespace-nowrap`: the name never wrapped, stayed on one line and was
   * clipped at the column edge with no ellipsis at all — worse than before.
   * Caught by a check that looked for sideways clipping, not just a clamp.
   */
  assert.match(FILE_NAME, /\bwhitespace-normal\b/);
  assert.match(FILE_NAME, /\bline-clamp-2\b/);
  assert.match(FILE_NAME, /\[overflow-wrap:anywhere\]/);
  assert.ok(!/\btruncate\b/.test(FILE_NAME), "a name is wrapped, not cut to one line");
  for (const [name, src] of [["table", tableSrc], ["cards", cards]]) {
    assert.match(src, /FILE_NAME/, `${name} uses the shared name style`);
  }
});

test("owner and group are stacked, not cut to one line", () => {
  // `my-blog-ngkx:my-blog-ngkx` on one line became "my-blo…:my-blo…".
  const cell = tableSrc.slice(tableSrc.indexOf("function OwnerCell"), tableSrc.indexOf("function PermissionsCell"));
  assert.match(cell, /flex-col/);
});

// ---- Files UX pass (2026-09-23): shortcuts, details, empty state, size ----

const shortcutsSrc = read("components/applications/files/file-shortcuts.jsx");

test("shortcuts exist only for layouts confirmed from a real listing", async () => {
  const { appShortcuts } = await import("../lib/files/app-shortcuts.js");
  // WordPress, read off a live panel: root holds wp-config.php and wp-content;
  // wp-content holds uploads, themes, plugins.
  assert.deepEqual(
    appShortcuts("wordpress").map((s) => s.path),
    ["wp-content/uploads", "wp-content/themes", "wp-content/plugins", "wp-config.php"],
  );
  // Anything unconfirmed gets nothing, not a guess that opens "folder is gone".
  for (const type of ["laravel", "joomla", "php", "static", null, undefined]) {
    assert.deepEqual(appShortcuts(type), [], `${type} must not get invented shortcuts`);
  }
  assert.match(shortcutsSrc, /if \(!shortcuts\.length\) return null;/);
  // A file shortcut opens the editor, not a folder view of its parent.
  assert.match(shortcutsSrc, /onAction\("edit", \{ name, path: target, type: "file" \}\)/);
  assert.match(shortcutsSrc, /prefetch=\{false\}/);
});

test("permissions say who can do what on hover, not in a separate panel", () => {
  /*
   * A Details panel was built and then dropped: everything on it was already
   * in the row. The one thing it added — the permissions said as a sentence —
   * sits on hover over the Permissions cell instead, the way Modified keeps
   * its exact date.
   */
  const cell = tableSrc.slice(tableSrc.indexOf("function PermissionsCell"), tableSrc.indexOf("function ActionsCell"));
  assert.match(cell, /const sentenceFor = useModeSentence\(\);/);
  assert.match(cell, /<TooltipContent[^>]*>\s*\{sentence \?\? file\.mode\}/);
  // One tooltip for the cell, carrying the world-writable warning too — not a
  // second, icon-only one beside it.
  assert.equal((cell.match(/<Tooltip>/g) ?? []).length, 1);
  assert.ok(!fs.existsSync(path.join(root, "components/applications/files/file-details-sheet.jsx")));
  assert.doesNotMatch(panel, /FileDetailsSheet|"details"/);
});

test("a folder's size is asked for where the answer appears", () => {
  const sizeCell = tableSrc.slice(tableSrc.indexOf("function SizeCell"), tableSrc.indexOf("function ModifiedCell"));
  assert.match(sizeCell, /onAction\?\.\("size", file\)/);
  assert.match(sizeCell, /t\("size\.calculate"\)/);
  assert.match(cards, /onClick=\{\(\) => onAction\("size", file\)\}/);
});

test("an empty folder explains itself and offers the way in, without a second blue button", () => {
  assert.match(panel, /t\("empty\.descriptionWrite"\) : t\("empty\.descriptionReadOnly"\)/);
  const empty = panel.slice(panel.indexOf("t(\"empty.descriptionWrite\")"), panel.indexOf("filtered.length === 0 ?"));
  assert.match(empty, /setUploadOpen\(true\)/);
  assert.doesNotMatch(empty, /<Button size="sm"/, "the toolbar's Upload is already the filled one");
  // Drag-and-drop always worked on this panel; now it says so.
  assert.match(panel, /t\("dropHint"\)/);
});

test("the phone card never splits a permission string across lines", () => {
  assert.match(cards, /className=\{cn\("whitespace-nowrap", isWorldWritable/);
  assert.match(cards, /flex flex-wrap gap-x-2 font-mono/);
});
