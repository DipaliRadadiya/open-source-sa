import test from "node:test";
import assert from "node:assert/strict";
import { countByApplication, hasNoDatabase } from "../lib/backups/database-availability.js";

test("databases are counted against the site that owns them", () => {
  const counts = countByApplication([
    { id: 1, name: "shop", application_id: 26 },
    { id: 2, name: "shop_logs", application_id: 26 },
    { id: 3, name: "blog", application_id: 31 },
  ]);
  assert.deepEqual(counts, { 26: 2, 31: 1 });
});

test("a database belonging to no site is credited to no site", () => {
  /*
   * Server-level databases carry a null `application_id`. Counting one against
   * some site would tell a site with no database that it has one, which is the
   * exact wrong answer this whole feature exists to prevent.
   */
  assert.deepEqual(
    countByApplication([
      { id: 1, name: "scratch", application_id: null },
      { id: 2, name: "adhoc" },
      { id: 3, name: "shop", application_id: 26 },
    ]),
    { 26: 1 },
  );
});

test("nothing in, nothing out", () => {
  assert.deepEqual(countByApplication([]), {});
  assert.deepEqual(countByApplication(undefined), {});
  assert.deepEqual(countByApplication(null), {});
});

test("a site absent from the counts has no database", () => {
  const counts = { 26: 2 };
  assert.equal(hasNoDatabase(counts, true, 26), false);
  assert.equal(hasNoDatabase(counts, true, 31), true);
  // Object keys are strings; a numeric id must still find its own row.
  assert.equal(hasNoDatabase({ 26: 1 }, true, "26"), false);
});

test("unknown is never reported as 'no database'", () => {
  /*
   * `known: false` means the list was truncated or the call failed. Answering
   * `true` there would print a confident "this site has no database" under a
   * site whose databases we simply never loaded — and that warning argues
   * someone out of backing up data they actually have.
   */
  assert.equal(hasNoDatabase({}, false, 26), null);
  assert.equal(hasNoDatabase(null, false, 26), null);
  // No site chosen yet: also not an answer.
  assert.equal(hasNoDatabase({ 26: 1 }, true, null), null);
  assert.equal(hasNoDatabase({ 26: 1 }, true, undefined), null);
});
