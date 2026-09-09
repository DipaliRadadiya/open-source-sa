import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const TABLE = readFileSync(
  new URL("../components/backups/backups-history-table.jsx", import.meta.url),
  "utf8",
);
const CARDS = readFileSync(
  new URL("../components/backups/backups-cards.jsx", import.meta.url),
  "utf8",
);
const SCHEMA = readFileSync(
  new URL("../lib/schemas/backup.js", import.meta.url),
  "utf8",
);

test("an absent destination is not the same as no destination", () => {
  /*
   * The backend sends `storage_destination_name` only when the relation was
   * eager-loaded, and made it ABSENT rather than null on purpose so a caller
   * can tell "no destination" from "not asked for". A frontend deployed ahead
   * of its backend — the live state of the test panel today — must show no
   * column at all, not one full of blanks that reads as data we failed to
   * load.
   */
  assert.match(
    TABLE,
    /storage_destination_name !== undefined/,
    "the table no longer distinguishes an absent field from a null one",
  );
  assert.match(
    CARDS,
    /storage_destination_name !== undefined/,
    "the cards no longer distinguish an absent field from a null one",
  );
});

test("the destination column is only hidden where it does not fit", () => {
  /*
   * Measured on both layouts, not chosen.
   *
   * The server-wide history carries a Site column; with the destination always
   * shown it overflows its container by 156px at 1024 and 28px at 1152, which
   * is sideways scrolling to reach Restore. A site's own Backups page has no
   * Site column and 156px more to spend — zero overflow from 1024 up — so
   * hiding it there was hiding it for nothing, and that is the page where
   * "where is this stored?" is actually asked. It was reported as missing from
   * exactly that page.
   */
  assert.match(
    TABLE,
    /cn\("max-w-40", showSite && "hidden xl:table-cell"\)/,
    "the destination column no longer decides its breakpoint by layout",
  );
  // Flexible, not pinned: a fixed width gave the table a 1244px floor and
  // overflowed by 270px at 1024 even with the Site column gone.
  assert.doesNotMatch(TABLE, /className: "hidden w-\d+ xl:table-cell"/, "the column pins a width again");
  // Truncated on a laptop, so the full name has to be reachable somehow.
  assert.match(TABLE, /title=\{name\}/, "a truncated destination name can no longer be read in full");
});

test("the field is declared rather than surviving on passthrough", () => {
  // A fetcher that leans on `.passthrough()` keeps working by accident and
  // stops the day the schema tightens.
  assert.match(SCHEMA, /storage_destination_name: z\.string\(\)\.nullish\(\)/);
});
