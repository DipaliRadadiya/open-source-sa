import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import {
  EMPTY_LOG_FILTERS,
  applicationLogSchema,
  logFiltersSchema,
} from "../lib/schemas/application-log.js";

const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
const errorsFor = (values) => {
  const result = logFiltersSchema.safeParse({ ...EMPTY_LOG_FILTERS, ...values });
  return result.success ? [] : result.error.issues.map((issue) => issue.message);
};

test("the filters accept what ShowApplicationLogRequest accepts", () => {
  assert.deepEqual(errorsFor({}), []);
  assert.deepEqual(errorsFor({ from: "1", status: "404", method: "POST", ip: "203.0.113.5", url: "/wp-login.php" }), []);
  for (const status of ["5xx", "4XX", "200", "199"]) assert.deepEqual(errorsFor({ status }), [], status);
  assert.deepEqual(errorsFor({ ip: "2001:db8::1" }), []);
});

test("the filters refuse what would come back as a 422", () => {
  assert.deepEqual(errorsFor({ from: "0" }), ["logFromInvalid"]);
  assert.deepEqual(errorsFor({ from: "-3" }), ["logFromInvalid"]);
  assert.deepEqual(errorsFor({ from: "1.5" }), ["logFromInvalid"]);
  for (const status of ["99x", "600", "4x", "40", "x04"]) {
    assert.deepEqual(errorsFor({ status }), ["logStatusInvalid"], status);
  }
  // `ip` is exact on the server: a range or a partial address never matches.
  assert.deepEqual(errorsFor({ ip: "1.2.3" }), ["logIpInvalid"]);
  assert.deepEqual(errorsFor({ ip: "10.0.0.0/8" }), ["logIpInvalid"]);
  assert.deepEqual(errorsFor({ url: "x".repeat(501) }), ["max500"]);
});

test("a read from a line keeps first_line; a read of the last lines says null", () => {
  const log = { key: "access", lines: [], truncated: false };
  assert.equal(applicationLogSchema.parse({ ...log, first_line: 1000 }).first_line, 1000);
  assert.equal(applicationLogSchema.parse({ ...log, first_line: null }).first_line, null);
});

test("empty filters are not sent, and only the access log offers the field filters", () => {
  assert.match(read("lib/api/application-logs.js"), /filter\(\(\[, value\]\) => value !== ""\)/);
  const panel = read("components/applications/logs/application-logs-panel.jsx");
  assert.match(panel, /const fieldFilters = current === "access";/);
  assert.match(panel, /fromAllowed=\{source\?\.kind === "file"\}/);
  // A different file has different line numbers and fields: switching tabs resets.
  assert.equal(panel.match(/setFilters\(EMPTY_LOG_FILTERS\);/g)?.length, 2);
  // Re-reading a fixed window shows nothing new, so Live does not poll it.
  assert.match(panel, /if \(!follow \|\| disabled \|\| fromRead\) return undefined;/);
});
