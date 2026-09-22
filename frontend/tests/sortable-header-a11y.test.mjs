import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { sortDirection } from "../lib/data-table/sort-direction.js";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

const TABLES = [
  "components/applications/applications-table.jsx",
  "components/databases/databases-table.jsx",
];

test("a sort button is called by its column, not by what it does", () => {
  /*
   * `aria-label="Sort by this column"` on every sort button. An aria-label
   * REPLACES an element's text, so the four column names vanished: the header
   * row was announced as "Sort by this column, PHP, Sort by this column,
   * System user, Sort by this column, Sort by this column".
   *
   * It also named the `<th>`, which names every cell under it — a size cell
   * read as "Sort by this column: 4.2 GB". Verified from the real
   * accessibility tree before and after; it now reads "Application PHP Status
   * System user Size Created".
   */
  const src = read("components/data-table/sort-header.jsx");
  assert.doesNotMatch(src, /aria-label=/, "the column name is the accessible name");

  // Not a general ban on aria-label — only on the one that overrides content.
  // A control with no text of its own still needs one.
  assert.match(read("components/data-table/refresh-button.jsx"), /aria-label=/);
});

test("the table says which column it is sorted by", () => {
  /*
   * Two kinds of sortable column here. TanStack's own (`canSort`) already set
   * `aria-sort`; the server-driven ones sort through `?sort=` and TanStack
   * reports them as unsortable, so they were announced as ordinary headers —
   * the list could be sorted by Size descending with nothing saying so.
   */
  const src = read("components/ui/data-table.jsx");
  assert.match(src, /sortDirection\(sortParam, header\.column\.columnDef\.meta\.sortKey\)/);

  // Every column that offers a sort must declare its key, or the header goes
  // back to being silent about a sort it is visibly applying.
  for (const file of TABLES) {
    const table = read(file);
    const offered = [...table.matchAll(/<SortHeader col="([^"]+)"/g)].map(([, col]) => col);
    const declared = [...table.matchAll(/\bsortKey: "([^"]+)"/g)].map(([, key]) => key);
    assert.ok(offered.length > 0, `${file} has sortable columns`);
    assert.deepEqual(
      [...offered].sort(),
      [...declared].sort(),
      `${file}: every sortable column needs a matching meta.sortKey`,
    );
  }
});

test("sort direction is read from the URL the same way it is written to it", () => {
  // The `<th>` and the button are different components reading one parameter;
  // a second opinion about its shape is how they drift apart.
  assert.equal(sortDirection("name", "name"), "ascending");
  assert.equal(sortDirection("-name", "name"), "descending");
  assert.equal(sortDirection(null, "name"), "none");
  assert.equal(sortDirection("created_at", "name"), "none");
  // Not a prefix match: `-names` must not read as `names` descending.
  assert.equal(sortDirection("-names", "name"), "none");
});
