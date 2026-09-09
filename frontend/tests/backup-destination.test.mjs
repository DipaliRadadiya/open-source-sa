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

test("the destination column flexes instead of pinning the table's width", () => {
  /*
   * Measured, not guessed. The table renders from lg up. A fixed-width
   * destination column gave it a 1244px floor, so it overflowed its own
   * container by 270px at 1024 and 142px at 1152 — sideways scrolling to
   * reach the row actions. Letting it flex brought every width back to zero
   * overflow.
   */
  assert.match(TABLE, /hidden max-w-40 xl:table-cell/, "the destination column pins a width again");
  assert.doesNotMatch(
    TABLE,
    /className: "hidden w-\d+ xl:table-cell"/,
    "the destination column pins a width again",
  );
  // Truncated on a laptop, so the full name has to be reachable somehow.
  assert.match(TABLE, /title=\{name\}/, "a truncated destination name can no longer be read in full");
});

test("the field is declared rather than surviving on passthrough", () => {
  // A fetcher that leans on `.passthrough()` keeps working by accident and
  // stops the day the schema tightens.
  assert.match(SCHEMA, /storage_destination_name: z\.string\(\)\.nullish\(\)/);
});
