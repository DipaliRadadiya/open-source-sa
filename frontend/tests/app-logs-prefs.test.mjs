import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { followFor, parseFollowPrefs, parseLinesPref, serializeFollowPrefs } from "../lib/logs/app-log-prefs.js";

const read = (p) => fs.readFileSync(p, "utf8");

test("Live is remembered per source, defaults where the reader never chose", () => {
  const prefs = parseFollowPrefs("error:off,access:on");
  assert.deepEqual(prefs, { error: false, access: true });
  assert.equal(followFor("error", prefs), false);
  assert.equal(followFor("access", prefs), true);
  assert.equal(followFor("application", {}), true, "default for a quiet source");
  assert.equal(followFor("access", {}), false, "the busy access log opens paused");
  assert.equal(serializeFollowPrefs({ error: true, access: false }), "error:on,access:off");
  assert.deepEqual(parseFollowPrefs("junk,<x>:on,error:maybe"), {}, "anything malformed is ignored");
});

test("the line count is remembered and clamped", () => {
  assert.equal(parseLinesPref("500", 200), 500);
  assert.equal(parseLinesPref("99999", 200), 5000);
  assert.equal(parseLinesPref(undefined, 200), 200);
  assert.equal(parseLinesPref("abc", 200), 200);
  const page = read("app/(app)/applications/[application]/logs/page.jsx");
  assert.match(page, /getApplicationLog\(id, selected, \{ lines \}\)/, "the first paint reads the remembered count");
});

test("a failed log list carries its status and reason to the error box", () => {
  const src = read("lib/applications/get-application-logs.js");
  assert.match(src, /return \{ logs: \[\], failed: true, status: res\.status, failure: "http", message \};/);
});

test("the search stops at the API's 200-character limit", () => {
  assert.match(read("components/logs/log-toolbar.jsx"), /maxLength=\{200\}/);
});
