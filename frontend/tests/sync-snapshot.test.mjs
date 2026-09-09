import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { serverSnapshot } from "../lib/sync/server-snapshot.js";

const run = { id: 1, status: "completed", finished: true, totals: { application: { found: 2 } } };
const items = [
  { id: 1, action: "skipped", model_id: null },
  { id: 2, action: "found", model_id: null },
];
const ignores = [{ resource_type: "application", resource_key: "old.example.com" }];

test("the same data through new objects is the same snapshot", () => {
  /*
   * The one that matters most. The server builds fresh objects on every
   * render, so if this compared identity the panel would remount on every
   * tick of its own poll and restart the scan it was showing.
   */
  assert.equal(
    serverSnapshot(run, items, ignores),
    serverSnapshot(
      { ...run, totals: { application: { found: 2 } } },
      items.map((item) => ({ ...item })),
      ignores.map((ignore) => ({ ...ignore })),
    ),
  );
});

test("a row the browser has never seen changes the snapshot", () => {
  // The reported bug: refresh fetched this row and the screen never showed it.
  const withNew = [...items, { id: 3, action: "found", model_id: null }];
  assert.notEqual(serverSnapshot(run, items, ignores), serverSnapshot(run, withNew, ignores));
});

test("a row that changed underneath us changes the snapshot", () => {
  // Not just added rows: id alone would rest on rows being immutable, which is
  // a property of the backend rather than of this screen.
  const adopted = [items[0], { id: 2, action: "adopted", model_id: 7 }];
  assert.notEqual(serverSnapshot(run, items, ignores), serverSnapshot(run, adopted, ignores));
});

test("a run that progressed changes the snapshot", () => {
  for (const changed of [
    { ...run, id: 2 },
    { ...run, status: "running" },
    { ...run, finished: false },
    { ...run, totals: { application: { found: 3 } } },
  ]) {
    assert.notEqual(
      serverSnapshot(run, items, ignores),
      serverSnapshot(changed, items, ignores),
      `${JSON.stringify(changed)} was treated as unchanged`,
    );
  }
});

test("ignoring something elsewhere changes the snapshot", () => {
  // Ignores can change from another session while this page is open.
  assert.notEqual(serverSnapshot(run, items, ignores), serverSnapshot(run, items, []));
  assert.notEqual(
    serverSnapshot(run, items, ignores),
    serverSnapshot(run, items, [{ resource_type: "system_user", resource_key: "old.example.com" }]),
  );
});

test("a server nobody has ever scanned is a stable snapshot, not a crash", () => {
  assert.equal(serverSnapshot(null, [], []), serverSnapshot(null, [], []));
  assert.equal(serverSnapshot(undefined, undefined, undefined), serverSnapshot(null, [], []));
});

test("the page mounts the panel with it", () => {
  const page = fs.readFileSync(
    new URL("../app/(app)/sync/page.jsx", import.meta.url),
    "utf8",
  );
  assert.match(
    page,
    /key=\{serverSnapshot\(run, items, ignores\)\}/,
    "the Sync panel is no longer keyed, so Refresh shows stale data again",
  );
});
