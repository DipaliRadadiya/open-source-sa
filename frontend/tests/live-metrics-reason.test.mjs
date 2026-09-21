import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * The live poll knew WHY it was failing and rendered a shrug.
 *
 * The API sends "Metrics collector is not running."; the badge said "Live
 * metrics unavailable", which is the category, not the reason. Same fault
 * Krishna named on the error page this morning — "why we cannot see actual
 * message instead of showing just Your server returned an error".
 */

const read = (p) => fs.readFileSync(p, "utf8");

test("a failing live poll reports the server's reason, not just the category", () => {
  /*
   * Driven against a stubbed API in a browser, reading innerText:
   *   500 + message   -> "Live metrics unavailable  Metrics collector is not running."
   *   500 + {}        -> "Live metrics unavailable"           (nothing invented)
   *   no response     -> "Live metrics unavailable"
   *   403 + message   -> "Live metrics unavailable  You do not have permission…"
   */
  const hook = read("components/dashboard/use-live-metrics.js");
  assert.match(hook, /setReason\(apiMessage\(error, null\)\)/);
  assert.match(hook, /setFailed\(false\);\s*\n\s*setReason\(null\);/, "a recovery must clear the old reason");
  assert.match(hook, /return \{ metrics, series, failed, reason, updatedAt, ratesReady \};/);

  const section = read("components/dashboard/live-metrics-section.jsx");
  assert.match(section, /function LiveStatus\(\{ failed, reason, updatedAt, timeZone \}\)/);
  assert.match(section, /\{failed && reason \?/, "only when there is one — never a blank line");
  assert.match(section, /reason=\{reason\}/);
});
