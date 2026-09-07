import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");
const table = fs.readFileSync(
  path.join(root, "components/applications/files/files-table.jsx"),
  "utf8",
);

/** Every `w-[N%]` in the column definitions, in order. */
function columnWidths() {
  return [...table.matchAll(/className: "(?:text-right )?w-\[(\d+)%\]/g)].map(
    ([, value]) => Number(value),
  );
}

test("the column widths still divide the table exactly", () => {
  // Under `fixedLayout` these are the whole layout. Summing to less leaves a
  // gap the browser fills arbitrarily; summing to more overflows every row.
  const widths = columnWidths();

  assert.equal(widths.length, 7, "expected seven sized columns");
  assert.equal(
    widths.reduce((sum, w) => sum + w, 0),
    100,
  );
});

test("owner and permissions are wider than the actions column they took from", () => {
  // The overlap this fixes: Owner and Permissions have a content floor
  // (`deploy:www-data`, `drwxr-xr-x`) while Actions is three icon buttons.
  const [, , , , owner, permissions, actions] = columnWidths();

  assert.ok(owner >= 16, `owner is ${owner}%, too narrow for owner:group`);
  assert.ok(permissions >= 15, `permissions is ${permissions}%, too narrow for a symbolic mode`);
  assert.ok(actions <= 19, `actions is ${actions}%, wider than three icon buttons need`);
});

test("the owner cell can actually truncate", () => {
  // A flex child defaults to min-width:auto, so `truncate` never engages and
  // the text runs out of the cell — which under `table-fixed` lands on top of
  // the next column. This is the bug, and min-w-0 is the fix.
  const cell = table.slice(table.indexOf("function OwnerCell"), table.indexOf("function PermissionsCell"));

  assert.match(cell, /flex min-w-0/, "the owner row must allow its children to shrink");
  assert.match(cell, /min-w-0 truncate/, "each name must be able to truncate");
  assert.match(cell, /title=/, "a truncated owner must still be readable in full");
});

test("a symbolic mode never wraps", () => {
  // `drwxr-xr-x` split across two lines is unreadable, and truncating it says
  // nothing at all — so the column is sized for it and the text is pinned.
  const cell = table.slice(table.indexOf("function PermissionsCell"), table.indexOf("function ActionsCell"));

  assert.match(cell, /whitespace-nowrap/);
});

test("the table keeps a floor width instead of crushing its columns", () => {
  // Percentages divide whatever width exists. The breakdown rail took 340px,
  // and below a floor the columns divide into less than their own content.
  assert.match(table, /tableClassName="min-w-\[\d+rem\]"/);
});

test("the floor is opt-in, so no other table is resized by it", () => {
  const dataTable = fs.readFileSync(
    path.join(root, "components/ui/data-table.jsx"),
    "utf8",
  );

  assert.match(dataTable, /tableClassName,/, "DataTable must accept the prop");
  assert.ok(
    !/tableClassName\s*=\s*["']/.test(dataTable),
    "tableClassName must not have a default that would apply to every table",
  );
});
