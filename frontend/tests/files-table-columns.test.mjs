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
  assert.ok(
    permissions >= 15,
    `permissions is ${permissions}%, too narrow for a symbolic mode`,
  );
  assert.ok(
    actions <= 19,
    `actions is ${actions}%, wider than three icon buttons need`,
  );
});

test("the owner cell can actually truncate", () => {
  // A flex child defaults to min-width:auto, so `truncate` never engages and
  // the text runs out of the cell — which under `table-fixed` lands on top of
  // the next column. This is the bug, and min-w-0 is the fix.
  const cell = table.slice(
    table.indexOf("function OwnerCell"),
    table.indexOf("function PermissionsCell"),
  );

  assert.match(
    cell,
    /flex min-w-0/,
    "the owner row must allow its children to shrink",
  );
  assert.match(cell, /min-w-0 truncate/, "each name must be able to truncate");
  assert.match(
    cell,
    /title=/,
    "a truncated owner must still be readable in full",
  );
});

test("a symbolic mode never wraps", () => {
  // `drwxr-xr-x` split across two lines is unreadable, and truncating it says
  // nothing at all — so the column is sized for it and the text is pinned.
  const cell = table.slice(
    table.indexOf("function PermissionsCell"),
    table.indexOf("function ActionsCell"),
  );

  assert.match(cell, /whitespace-nowrap/);
});

test("the listing never scrolls sideways to reach its own row actions", () => {
  // Widening the table to fit seven columns put Download and Copy behind a
  // horizontal scroll — the two controls people reach for most, and a worse
  // problem than the overlap it was solving. The rail waits for the width
  // instead.
  assert.ok(
    !/min-w-\[\d+rem\]/.test(table),
    "the file table must not force a width its container cannot hold",
  );

  const page = fs.readFileSync(
    path.join(root, "app/(app)/applications/[application]/files/page.jsx"),
    "utf8",
  );

  // The rail is gone entirely. It cost 340px, which is why it was held back to
  // 2xl; holding it back only moved that cost to the widest screens instead of
  // removing it. The breakdown is a sheet off the toolbar now, so the listing
  // gets the whole row at every size — and nothing may put a column beside it
  // again without deciding this afresh.
  assert.ok(
    !/grid-cols-\[minmax\(0,1fr\)_minmax\(0,340px\)\]/.test(page),
    "the breakdown must not take a column beside the listing",
  );
  assert.ok(
    !/\b(?:xl|2xl):grid-cols-\[/.test(page),
    "the listing must not share its row with a side rail at any breakpoint",
  );

  // And the breakdown is still reachable — a guard that let it be deleted
  // would be satisfied by removing the feature.
  const panel = fs.readFileSync(
    path.join(root, "components/applications/files/files-panel.jsx"),
    "utf8",
  );

  // As JSX, not as an identifier: an import left behind after the element was
  // deleted satisfied the looser form, which a mutation run caught.
  assert.match(
    panel,
    /<SizeBreakdownSheet\b/,
    "the breakdown must still be rendered, not merely imported",
  );
});

test("owner is not sortable, and says so next to the column", () => {
  // The sort did not answer the question it was added for. It orders by
  // `owner` alone while the column renders `owner:group`, and folders are
  // pinned above files first regardless — so any owner appearing in both is
  // scattered. A control that reorders the list without answering the question
  // is worse than none, because its presence claims otherwise.
  // To the end of the column object — at a four-space indent. `indexOf("},")`
  // alone stops at `meta: { className: … }`, one line in, and would pass on a
  // column that never opted out.
  const owner = table.slice(table.indexOf('accessorKey: "owner"'));
  const definition = owner.slice(0, owner.indexOf("\n    },"));

  assert.match(
    definition,
    /enableSorting: false/,
    "the owner column must not offer a sort",
  );
});

test("every column that is not sortable is explicit about it", () => {
  // `sortable` is on the table, so sorting is opt-OUT per column. A column
  // added without a decision gets a sort button nobody chose to give it.
  // Bounded to the array itself: `defaultSorting={[{ id: "name" }]}` sits
  // below it and would otherwise count as an eighth column.
  const afterStart = table.slice(table.indexOf("const columns = ["));
  const columns = afterStart.slice(0, afterStart.indexOf("\n  ];"));
  const sortable = [
    ...columns.matchAll(/accessorKey: "(\w+)"|id: "(\w+)"/g),
  ].map(([, accessor, id]) => accessor ?? id);
  const optedOut = [...columns.matchAll(/enableSorting: false/g)].length;

  // name, size and modified_at carry a sortingFn; everything else opts out.
  const withSortFn = [...columns.matchAll(/sortingFn:/g)].length;

  assert.equal(
    sortable.length,
    withSortFn + optedOut,
    `${sortable.length} columns but ${withSortFn} sort functions and ${optedOut} opt-outs — one column decided nothing`,
  );
});
