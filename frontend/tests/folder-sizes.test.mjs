import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { folderSizesResponseSchema } from "../lib/schemas/file.js";
import { measuredSize, sizeShare, sizeSortKey } from "../lib/files/folder-sizes.js";

const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

const answer = {
  path: "wp-content",
  sizes: { plugins: { size: 9579803, size_human: "9.1 MB" }, uploads: { size: 52428800, size_human: "50 MB" } },
  total: { size: 62012699, size_human: "59 MB" },
  complete: true,
  measured_at: "01-10-2026 11:50:00",
};
const dir = (name) => ({ type: "dir", name, size: 4096 });

test("the documented answer parses; PHP's empty sizes `[]` is an empty map, not a failure", () => {
  assert.equal(folderSizesResponseSchema.parse(answer).sizes.uploads.size_human, "50 MB");
  const empty = folderSizesResponseSchema.parse({ path: "", sizes: [], total: { size: 4096, size_human: "4 KB" }, complete: true, measured_at: "01-10-2026 11:50:00" });
  assert.deepEqual(empty.sizes, {});
  // complete:false comes with total null.
  assert.equal(folderSizesResponseSchema.parse({ ...answer, total: null, complete: false }).total, null);
});

test("a folder missing from sizes is not measured (null), never 0", () => {
  const sizes = folderSizesResponseSchema.parse(answer);
  assert.equal(measuredSize(dir("plugins"), sizes).size_human, "9.1 MB");
  assert.equal(measuredSize(dir("themes"), sizes), null);
  // A file never takes a folder's measurement, even with the same name.
  assert.equal(measuredSize({ type: "file", name: "plugins" }, sizes), null);
  assert.equal(measuredSize(dir("plugins"), null), null);
});

test("the share bar needs a complete total", () => {
  const sizes = folderSizesResponseSchema.parse(answer);
  const uploads = measuredSize(dir("uploads"), sizes);
  assert.ok(Math.abs(sizeShare(uploads, sizes) - 52428800 / 62012699) < 1e-9);
  assert.equal(sizeShare(uploads, { ...sizes, complete: false }), null);
  assert.equal(sizeShare(uploads, { ...sizes, total: null }), null);
  assert.equal(sizeShare(null, sizes), null);
});

test("biggest first puts unmeasured folders last and ignores the 4 KB entry size", () => {
  const sizes = folderSizesResponseSchema.parse(answer);
  const rows = [dir("themes"), dir("plugins"), dir("uploads")];
  const sorted = [...rows].sort((a, b) => sizeSortKey(b, sizes) - sizeSortKey(a, sizes)).map((r) => r.name);
  assert.deepEqual(sorted, ["uploads", "plugins", "themes"]);
  assert.equal(sizeSortKey({ type: "file", name: "a", size: 10 }, sizes), 10);
  assert.match(read("components/applications/files/files-table.jsx"), /sortDescFirst: true/);
});

test("measure again asks the server to walk now", () => {
  assert.match(read("lib/api/files.js"), /files\/sizes`, \{ params: \{ path, \.\.\.\(refresh \? \{ refresh: 1 \} : \{\}\) \}, signal \}/);
  assert.match(read("components/applications/files/use-folder-sizes.js"), /requestSizes\(appId, path, true, ctrl\.signal\)/);
});

test("measured_at is read as the API's UTC clock, and never shown in the future", () => {
  const status = read("components/applications/files/folder-sizes-status.jsx");
  assert.match(status, /parseApiWallClock\(data\.measured_at\)/);
  assert.match(status, /Math\.min\(at\.getTime\(\), now\.getTime\(\)\)/);
});

test("folder links never prefetch: each prefetch renders the folder's page, listing and type walk included", () => {
  for (const file of ["files-table.jsx", "files-cards.jsx", "file-breadcrumb.jsx"]) {
    const src = read(`components/applications/files/${file}`);
    const links = src.match(/<Link\b[^>]*>/gs) ?? [];
    assert.ok(links.length > 0, file);
    for (const link of links) assert.match(link, /prefetch=\{false\}/, `${file}: ${link.slice(0, 80)}`);
  }
});

test("folders stay above files when a column sorts descending", () => {
  // TanStack negates a comparator for desc, which put files first on "biggest first".
  const src = read("components/applications/files/files-table.jsx");
  assert.match(src, /return \(aDir \? -1 : 1\) \* \(desc \? -1 : 1\);/);
  for (const id of ["name", "size", "modified_at"]) assert.match(src, new RegExp(`isDesc\\("${id}"\\)\\)`));
  // The hidden-files toggle renders this page too, so it never prefetches either.
  assert.equal((read("components/applications/files/files-panel.jsx").match(/href=\{hiddenHref\}[\s\S]{0,120}?prefetch=\{false\}/g) ?? []).length, 2);
});
