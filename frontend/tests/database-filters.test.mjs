import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const SOURCE = readFileSync(new URL("../lib/databases/get-databases.js", import.meta.url), "utf8");

/*
 * These guard two values the API validates and the panel got wrong, both of
 * which failed SILENTLY: a 422 here returns `known: false` or a count of 0,
 * which reads as "nothing to warn about" rather than "the request broke". The
 * whole no-database feature was dead on a real server for exactly this reason,
 * and every mocked test passed while it was.
 */

test("filter[attached] is sent as 0/1, never as the words", () => {
  // Laravel's `boolean` rule takes true|false|1|0|"1"|"0" and 422s "true"/"false".
  const attached = [...SOURCE.matchAll(/"filter\[attached\]":\s*([^,\n]+)/g)].map((m) => m[1].trim());

  assert.ok(attached.length >= 2, "expected the attached filter to be used");
  for (const value of attached) {
    assert.match(value, /^[01]$/, `filter[attached] must be 0 or 1, got ${value}`);
  }
});

test("per_page only ever asks for a size the API offers", () => {
  // IndexDatabasesRequest::PAGE_SIZES — anything else is a 422, including 1.
  const allowed = new Set([10, 20, 30, 50, 100]);
  const sizes = [...SOURCE.matchAll(/per_page:\s*(\d+)/g)].map((m) => Number(m[1]));

  assert.ok(sizes.length > 0, "expected explicit per_page values");
  for (const size of sizes) {
    assert.ok(allowed.has(size), `per_page ${size} is not one of ${[...allowed].join("/")}`);
  }
});
