import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

import { PER_PAGE_OPTIONS } from "../lib/schemas/user.js";

// The helper is module-private, so it is exercised through the same source the
// fetcher uses rather than being exported purely for a test.
const src = readFileSync(new URL("../lib/backups/get-backups.js", import.meta.url), "utf8");
const body = src.slice(src.indexOf("async function perPage("));
// Stands in for lib/tables/remembered-per-page.js (it reads a cookie); `remembered`
// is the size last picked on any list.
let remembered = 10;
const perPageFrom = async (value) => (PER_PAGE_OPTIONS.includes(Number(value)) ? Number(value) : remembered);
const perPage = new Function(
  "perPageFrom",
  `${body.slice(0, body.indexOf("\n}") + 2)}; return perPage;`,
)(perPageFrom);

test("the four offered sizes pass through", async () => {
  for (const n of PER_PAGE_OPTIONS) assert.equal(await perPage(String(n)), n);
});

test("a size the API would refuse falls back instead of failing the page", async () => {
  // `?per_page=999` was answered 422 by the API (max:100), and the history page
  // then rendered its whole load-failure panel over one junk query param.
  assert.equal(await perPage("999"), 10);
  assert.equal(await perPage("abc"), 10);
  assert.equal(await perPage("-5"), 10);
});

test("a size the API accepts but the selector does not offer falls back", async () => {
  // 7 is a valid integer 1-100, so the list really paginated by seven — under a
  // rows-per-page box that rendered blank, because 7 is not one of its items.
  assert.equal(await perPage("7"), 10);
});

test("absent resolves to the selector's own default, not the API's", async () => {
  /*
   * This test used to assert the opposite -- "absent stays absent, so the API
   * applies its own default" -- and that sentence was the bug written down as
   * an intention. Dropping the parameter handed the choice to
   * `BackupController::index`, whose default is 20, while `PerPageSelect`
   * renders "10" for an empty URL. With 11-20 backups the page showed every
   * row under a box reading 10 and no pager at all, because one page of 20
   * makes `last_page` 1.
   *
   * The number the screen claims and the number it asks for have to be the
   * same number, and neither end may assume the other's default.
   */
  assert.equal(await perPage(undefined), PER_PAGE_OPTIONS[0]);
  assert.equal(await perPage(null), PER_PAGE_OPTIONS[0]);
  // The value `PerPageSelect` displays when the URL carries no `per_page`.
  assert.equal(await perPage(undefined), 10);
});

test("a number from our own code is left alone", async () => {
  // The layout asks for `per_page: 5` to find an in-flight restore without
  // pulling a screenful. Clamping that would be the guard overruling its caller.
  assert.equal(await perPage(5), 5);
  assert.equal(await perPage(100), 100);
});

test("absent or invalid uses the size last picked on any list (Krishna, 5 Nov)", async () => {
  remembered = 20;
  try {
    assert.equal(await perPage(undefined), 20);
    assert.equal(await perPage("999"), 20);
    assert.equal(await perPage("50"), 50);
    assert.equal(await perPage(5), 5);
  } finally {
    remembered = 10;
  }
});
