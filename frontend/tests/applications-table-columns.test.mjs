import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");
const table = fs.readFileSync(
  path.join(root, "components/applications/applications-table.jsx"),
  "utf8",
);

/*
 * The applications list, sized.
 *
 * It had no column widths and no `fixedLayout`, so the browser's default `auto`
 * layout made every column as wide as its longest cell. A 61-character site
 * name — "ServerAvatar Managed Cloud Hosting PVt Ltd Surat Gujarat India" —
 * took the Application column to 657px and the table 547px past its container
 * at 1024px, with Size, Created and the row menu off the end of a sideways
 * scrollbar. The `truncate` classes were all already there; nothing bounded
 * them, so none of them ever fired.
 *
 * Measured in a real build at the four content widths this table can have:
 * 704 / 960 / 1120 / 1216px. The shell is a 16rem sidebar plus
 * `max-w-screen-xl p-8`, so the viewport is NOT the container — and below
 * 1024px the cards render instead of this table.
 */

/** The `meta.className` of every column, in definition order. */
function columnClasses() {
  return [...table.matchAll(/meta: \{ className: "([^"]+)" \}/g)].map(([, value]) => value);
}

/** Widths at one breakpoint. `prefix` is "" for the base, "xl:" above it. */
function widthsAt(prefix) {
  return columnClasses()
    .map((className) => {
      const found = [...className.matchAll(/(?:^| )(xl:)?w-\[(\d+)%\]/g)].find(
        ([, seen]) => (seen ?? "") === prefix,
      );
      // A column with no override at this breakpoint keeps its base width.
      if (found) return Number(found[2]);
      if (prefix === "xl:") {
        const base = [...className.matchAll(/(?:^| )w-\[(\d+)%\]/g)][0];
        return base ? Number(base[1]) : null;
      }
      return null;
    })
    .filter((width) => width !== null);
}

test("the table is fixed-layout, or the widths below are only hints", () => {
  // In `auto` layout a percentage is advisory and the longest cell still wins,
  // which is the exact bug this file exists about. Without this line the widths
  // can all be correct and the table still overflows.
  assert.match(table, /<DataTable[^>]*fixedLayout/s);
});

test("each breakpoint's columns divide the table exactly", () => {
  // Summing under 100 leaves a gap the browser fills arbitrarily; over 100
  // overflows every row — which is what it used to do.
  const base = widthsAt("");
  const wide = widthsAt("xl:");

  // Six below xl (Created is hidden), seven from xl up.
  assert.equal(base.length, 6, `expected six sized columns below xl, got ${base}`);
  assert.equal(wide.length, 7, `expected seven sized columns from xl, got ${wide}`);
  assert.equal(base.reduce((sum, w) => sum + w, 0), 100);
  assert.equal(wide.reduce((sum, w) => sum + w, 0), 100);
});

test("Created is the column that hides on a narrow screen, and only it", () => {
  /*
   * Seven columns do not fit 704px. Sharing it evenly cut Type to "Next…" and
   * Owner to "akaunti…" — not narrower columns but columns that had stopped
   * saying anything. Created is the one whose absence costs least: it is not
   * actionable, it never changes, and the detail page carries it.
   */
  const hidden = columnClasses().filter((className) => className.includes("hidden"));
  assert.equal(hidden.length, 1);
  assert.match(hidden[0], /^hidden xl:table-cell/);
});

test("Size has room for the longest locale's heading", () => {
  /*
   * Spanish "Tamaño" plus the sort arrow needs 93px. At 10% of 704px the
   * column was 70px and the heading hung 23px outside it — English looked
   * perfect and two locales were broken, which is why this is measured from
   * the widest word rather than the shortest.
   */
  const size = columnClasses().find((className) => /w-\[14%\]/.test(className));
  assert.ok(size, "the Size column lost the width its Spanish heading needs");
});

test("every cell that can hold a long value can also shrink", () => {
  // `block` before `truncate`: on an inline span there is no box to overflow,
  // so the ellipsis never appears. And a clipped value needs a title, or the
  // row simply stops saying which account a site runs as.
  for (const cell of ["TypeCell", "OwnerCell"]) {
    const body = table.slice(table.indexOf(`function ${cell}`), table.indexOf(`function ${cell}`) + 400);
    assert.match(body, /block truncate/, `${cell} truncates inline, so it never truncates`);
    assert.match(body, /title=\{value\}/, `${cell} clips without saying what it clipped`);
  }

  assert.match(table, /<span className="truncate" title=\{row\.original\.name\}>/);
});

test("the badges wrap under the name instead of squeezing it", () => {
  /*
   * "Staging" and "No database" are `shrink-0`, so on a 704px screen they took
   * the room and left the name 45px — three characters. Wrapping them to a
   * second line is what the cards layout already does with the same pair.
   */
  assert.match(table, /flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1/);
});
