import { test } from "node:test";
import assert from "node:assert/strict";
import { syncRunResponseSchema, syncRunSchema } from "../lib/schemas/sync.js";

/**
 * PHP has one array type, so an empty `$totals` serializes as `[]` and a
 * populated one as `{}`. A brand-new run is always in the first state, which is
 * exactly the response the panel parses when the user clicks "Scan the server".
 *
 * Rejecting it made a 201 look like a failure: the toast said the scan could not
 * start while it was already running, and the poll could not read the run back.
 */

/** The verbatim 201 body from POST /server/sync for a freshly created run. */
const FRESH_RUN = {
  sync: {
    id: 1,
    mode: "preview",
    status: "pending",
    finished: false,
    finished_at: null,
    options: { only: [], include_firewall: false, include_ignored: false },
    started_at: null,
    totals: [],
  },
};

test("a fresh run's empty totals parse", () => {
  const parsed = syncRunResponseSchema.safeParse(FRESH_RUN);
  assert.equal(parsed.success, true, parsed.error?.issues?.[0]?.message);
  assert.equal(parsed.data.sync.id, 1);
  assert.equal(parsed.data.sync.status, "pending");
});

test("empty totals arrive as an object, not an array", () => {
  // `totals[type]` is the access the summary makes; an array would survive
  // Object.entries and then read undefined for every type.
  const { totals } = syncRunResponseSchema.parse(FRESH_RUN).sync;
  assert.deepEqual(totals, {});
  assert.equal(Array.isArray(totals), false);
});

test("totals keyed by resource type still parse", () => {
  const run = syncRunSchema.parse({
    ...FRESH_RUN.sync,
    status: "completed",
    finished: true,
    totals: { application: { found: 3, adopted: 0, skipped: 1, failed: 0 } },
  });

  assert.equal(run.totals.application.found, 3);
  assert.equal(run.totals.application.skipped, 1);
});

test("a per-type total fills in the counters it omits", () => {
  const run = syncRunSchema.parse({ ...FRESH_RUN.sync, totals: { cronjob: { found: 2 } } });

  assert.deepEqual(run.totals.cronjob, { found: 2, adopted: 0, skipped: 0, failed: 0 });
});

test("an item whose evidence is an empty array survives", () => {
  // The item itself matters more than its evidence: rejecting `[]` dropped the
  // whole discovered row from the results list.
  const run = syncRunSchema.parse({
    ...FRESH_RUN.sync,
    items: [
      { id: 7, resource_type: "cronjob", resource_key: "0 3 * * * /usr/bin/foo", evidence: [] },
      { id: 8, resource_type: "application", resource_key: "example.com", evidence: { path: "/var/www" } },
    ],
  });

  assert.equal(run.items.length, 2);
  assert.deepEqual(run.items[0].evidence, {});
  assert.equal(run.items[1].evidence.path, "/var/www");
});
