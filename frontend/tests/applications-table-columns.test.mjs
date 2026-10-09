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

/**
 * The `meta.className` of every column, in definition order.
 *
 * Matches the property, not the whole `meta` object: `meta` also carries
 * `sortKey` now, and a pattern anchored on the closing brace silently found
 * three columns instead of six — reporting a width bug in a table nobody had
 * touched. A column that gains any second meta key must not look like a
 * column that lost its width.
 */
function columnClasses(set = "withBackups") {
  // Since 7 Oct the widths live in two fixed sets (with and without the Last backup
  // column), each column a `key: "classes"` line.
  const body = table.slice(table.indexOf(`  ${set}: {`), table.indexOf("},", table.indexOf(`  ${set}: {`)));
  return [...body.matchAll(/^\s+\w+: "([^"]+)",$/gm)].map(([, value]) => value);
}

const ORDER = ["", "xl:", "2xl:"];

/** Width of one column at a breakpoint, or null when it is hidden there. */
function widthAt(className, index) {
  const shownFrom = className.match(/hidden (\w+):table-cell/)?.[1];
  if (shownFrom && ORDER.indexOf(`${shownFrom}:`) > index) return null;
  let width = null;
  for (const prefix of ORDER.slice(0, index + 1)) {
    const hit = [...className.matchAll(/(?:^| )([\w]+:)?w-\[(\d+)%\]/g)].find(([, seen]) => (seen ?? "") === prefix);
    if (hit) width = Number(hit[2]);
  }
  assert.ok(width !== null, `a shown column has no width at "${ORDER[index] || "lg"}": ${className}`);
  return width;
}

test("the table is fixed-layout, or the widths below are only hints", () => {
  // In `auto` layout a percentage is advisory and the longest cell still wins,
  // which is the exact bug this file exists about. Without this line the widths
  // can all be correct and the table still overflows.
  assert.match(table, /<DataTable[^>]*fixedLayout/s);
});

test("each breakpoint's columns divide the table exactly", () => {
  // Summing under 100 leaves a gap the browser fills arbitrarily; over 100
  // overflows every row. Columns join as the table widens, so each breakpoint
  // is summed over the columns it actually shows, for both sets.
  for (const set of ["withBackups", "withoutBackups"]) {
    for (const index of ORDER.keys()) {
      const total = columnClasses(set)
        .map((className) => widthAt(className, index))
        .reduce((sum, width) => sum + (width ?? 0), 0);
      assert.equal(total, 100, `${set} at "${ORDER[index] || "lg"}"`);
    }
  }
});

test("the least useful columns are the ones that hide on narrower tables", () => {
  /*
   * Eight columns do not fit 704px, or even 1120px: sharing the room evenly cut
   * every value ("Static fil…", "Not pro…") and pushed the Russian, Spanish,
   * French and Portuguese status pills out of their column. Created goes first
   * (not actionable, the detail page carries it) with System user, then Size (7 Oct).
   */
  const keys = (set) => {
    const body = table.slice(table.indexOf(`  ${set}: {`), table.indexOf("},", table.indexOf(`  ${set}: {`)));
    return Object.fromEntries([...body.matchAll(/^\s+(\w+): "([^"]+)",$/gm)].map(([, key, value]) => [key, value]));
  };
  for (const set of ["withBackups", "withoutBackups"]) {
    const cols = keys(set);
    const hidden = Object.entries(cols).filter(([, className]) => className.includes("hidden"));
    assert.deepEqual(hidden.map(([key]) => key).sort(), ["created", "owner", "size"], set);
    assert.match(cols.created, /^hidden 2xl:table-cell/);
    assert.match(cols.owner, /^hidden 2xl:table-cell/);
    assert.match(cols.size, /^hidden xl:table-cell/);
  }
});

test("Size has room for the longest locale's heading", () => {
  /*
   * Spanish "Tamaño" plus the sort arrow needs 93px. At 10% of 704px the
   * column was 70px and the heading hung 23px outside it — English looked
   * perfect and two locales were broken, which is why this is measured from
   * the widest word rather than the shortest. The table is 960px at 1280 and
   * 1215px at 1536, so 10% and 8% are the floors.
   */
  for (const set of ["withBackups", "withoutBackups"]) {
    const body = table.slice(table.indexOf(`  ${set}: {`), table.indexOf("},", table.indexOf(`  ${set}: {`)));
    const size = body.match(/size: "([^"]+)"/)[1];
    assert.ok(Number(size.match(/(?:^| )xl:w-\[(\d+)%\]/)[1]) >= 10, `${set}: Size too narrow at xl`);
    assert.ok(Number(size.match(/2xl:w-\[(\d+)%\]/)[1]) >= 8, `${set}: Size too narrow at 2xl`);
  }
});

test("Status fits the widest locale's pill", () => {
  // Russian "Приостановлено" needs 166px: 24% of 704, 18% of 960, 14% of 1215.
  for (const set of ["withBackups", "withoutBackups"]) {
    const body = table.slice(table.indexOf(`  ${set}: {`), table.indexOf("},", table.indexOf(`  ${set}: {`)));
    assert.match(body, /status: "w-\[24%\] xl:w-\[18%\] 2xl:w-\[14%\]"/, set);
  }
});

test("every cell that can hold a long value can also shrink", () => {
  // `block` before `truncate`: on an inline span there is no box to overflow,
  // so the ellipsis never appears. And a clipped value needs a title, or the
  // row simply stops saying which account a site runs as.
  // `PhpCell` replaced `TypeCell` when the Type column was dropped — the logo
  // names the framework now. Same requirement either way: the cell holds a
  // value that can be longer than its column.
  const owner = table.slice(table.indexOf("function OwnerCell"), table.indexOf("function OwnerCell") + 400);
  assert.match(owner, /block truncate/, "OwnerCell truncates inline, so it never truncates");
  assert.match(owner, /title=\{value\}/, "OwnerCell clips without saying what it clipped");
  // Runs on and Last backup wrap instead: a cut "Node.js 24.…" or "Nicht gesch…" said
  // nothing (7 Oct). Runs on is one plain line with no logo, the exact version as a title.
  const runsOn = table.slice(table.indexOf("function RunsOnCell"), table.indexOf("function LastBackupCell"));
  assert.match(runsOn, /title=\{runtimeLabel\(runtime, t, tDocker, \{ full: true \}\)\}/);
  assert.match(runsOn, /whitespace-normal break-words/);
  assert.doesNotMatch(runsOn, /<img|Container/);
  const lastBackup = table.slice(table.indexOf("function LastBackupCell"), table.indexOf("function OwnerCell"));
  assert.doesNotMatch(lastBackup, /truncate/);
  // One line with an icon, as in the prototype; red only for "Not set up" and "Failed".
  assert.match(lastBackup, /<ArchiveRestore /);
  assert.match(lastBackup, /t\("backups\.notSetUp"\)/);
  // …and "Not set up" links to the Backups tab, where "Set up backups" is.
  assert.match(lastBackup, /href=\{`\/applications\/\$\{row\.original\.id\}\/backups`\}/);

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
