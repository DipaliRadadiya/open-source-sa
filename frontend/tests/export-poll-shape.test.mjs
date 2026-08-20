import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const source = fs.readFileSync(
  path.join(import.meta.dirname, "../components/databases/database-exports.jsx"),
  "utf8",
);

test("the poll's finished branch does not discard its rows", () => {
  // `setPolled(null)` falls back to `initial`, the server render from when the
  // page loaded — where a just-finished export was still queued. Discarding
  // here reverted a completed export to "Waiting", put it back in flight, and
  // re-polled forever without ever showing the result.
  //
  // Scoped to the branch rather than the file: retiring the override when a
  // NEWER server render arrives is the correct use of the same call, and is
  // what makes a deleted export leave the list.
  const finishedBranch = source.slice(
    source.indexOf("if (!still) {"),
    source.indexOf("} catch {"),
  );

  assert.ok(finishedBranch.length > 0, "could not find the finished branch");
  assert.ok(
    !/setPolled\(null\)/.test(finishedBranch),
    "the finished branch must keep the rows it just fetched",
  );
});

test("a finished run still refreshes the server render", () => {
  // The rows on screen come from the poll, but a later navigation must not read
  // something older than what the user is looking at.
  assert.ok(/if \(!still\) \{[\s\S]*?router\.refresh\(\)/.test(source));
});

test("a newer server render retires the polled override", () => {
  // The two halves of this have to hold together. Dropping the polled rows on
  // the last poll reverted a finished export to "Waiting"; keeping them forever
  // meant a deleted export never left the list, because `remove()` calls
  // `router.refresh()` and nothing was reading `initial` any more.
  //
  // So the override stands down when the prop identity changes — which is what
  // a refresh produces — rather than on a timer or at the end of a poll.
  assert.ok(
    /if \(seenInitial !== initial\) \{[\s\S]*?setPolled\(null\)/.test(source),
    "polled must yield to a newer server render",
  );
});

test("deleting an export asks the server for the list again", () => {
  assert.ok(/await deleteExport\([\s\S]*?router\.refresh\(\)/.test(source));
});
