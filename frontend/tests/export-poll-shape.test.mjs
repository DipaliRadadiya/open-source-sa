import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const source = fs.readFileSync(
  path.join(import.meta.dirname, "../components/databases/database-exports.jsx"),
  "utf8",
);

test("the poll never discards its rows for the page-load snapshot", () => {
  // `setPolled(null)` falls back to `initial`, which is the server render from
  // when the page loaded — where a just-finished export was still queued. A
  // completed export therefore reverted to "Waiting" the instant it succeeded,
  // went back in flight, and re-polled forever without ever showing the result.
  assert.ok(
    // The statement form only — the comment above it quotes the old call while
    // explaining why it went.
    !/^\s*setPolled\(null\);/m.test(source),
    "setPolled(null) reverts the list to the stale page-load snapshot",
  );
});

test("a finished run still refreshes the server render", () => {
  // The rows on screen come from the poll, but a later navigation must not read
  // something older than what the user is looking at.
  assert.ok(/if \(!still\) \{[\s\S]*?router\.refresh\(\)/.test(source));
});
