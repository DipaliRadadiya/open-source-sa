import { test } from "node:test";
import assert from "node:assert/strict";
import { outOfRangeHref } from "../lib/tables/out-of-range-href.js";

test("goes to the end of the list, not back to page 1", () => {
  // Deleting the only row on page 3 of 3 belongs on page 2.
  assert.equal(outOfRangeHref("page=3", 2), "?page=2");
  assert.equal(outOfRangeHref("page=9", 4), "?page=4");
});

test("page 1 is the bare URL, matching how the pager writes it", () => {
  assert.equal(outOfRangeHref("page=3", 1), "?");
  assert.equal(outOfRangeHref("", 1), "?");
});

test("filters survive; only the page moves", () => {
  assert.equal(outOfRangeHref("page=3&status=active", 2), "?status=active&page=2");
  // Including when the destination is page 1 and `page` disappears entirely.
  assert.equal(outOfRangeHref("page=3&search=abc", 1), "?search=abc");
});

test("a missing or odd lastPage falls back to the start rather than guessing", () => {
  assert.equal(outOfRangeHref("page=3"), "?");
  assert.equal(outOfRangeHref("page=3", 0), "?");
});
