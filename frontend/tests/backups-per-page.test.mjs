import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

import { PER_PAGE_OPTIONS } from "../lib/schemas/user.js";

// The helper is module-private, so it is exercised through the same source the
// fetcher uses rather than being exported purely for a test.
const src = readFileSync(new URL("../lib/backups/get-backups.js", import.meta.url), "utf8");
const body = src.slice(src.indexOf("function perPage("));
const perPage = new Function(
  "PER_PAGE_OPTIONS",
  `${body.slice(0, body.indexOf("\n}") + 2)}; return perPage;`,
)(PER_PAGE_OPTIONS);

test("the four offered sizes pass through", () => {
  for (const n of PER_PAGE_OPTIONS) assert.equal(perPage(String(n)), n);
});

test("a size the API would refuse falls back instead of failing the page", () => {
  // `?per_page=999` was answered 422 by the API (max:100), and the history page
  // then rendered its whole load-failure panel over one junk query param.
  assert.equal(perPage("999"), 10);
  assert.equal(perPage("abc"), 10);
  assert.equal(perPage("-5"), 10);
});

test("a size the API accepts but the selector does not offer falls back", () => {
  // 7 is a valid integer 1-100, so the list really paginated by seven — under a
  // rows-per-page box that rendered blank, because 7 is not one of its items.
  assert.equal(perPage("7"), 10);
});

test("absent stays absent, so the API applies its own default", () => {
  assert.equal(perPage(undefined), undefined);
  assert.equal(perPage(null), undefined);
});

test("a number from our own code is left alone", () => {
  // The layout asks for `per_page: 5` to find an in-flight restore without
  // pulling a screenful. Clamping that would be the guard overruling its caller.
  assert.equal(perPage(5), 5);
  assert.equal(perPage(100), 100);
});
