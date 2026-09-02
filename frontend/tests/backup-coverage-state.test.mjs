import test from "node:test";
import assert from "node:assert/strict";

import { classify } from "../lib/backups/coverage-state.js";

/**
 * `classify` decides the badge on the coverage table. It used to look only at
 * the schedule, so a site whose last backup FAILED was badged green
 * "Protected" — the one screen whose job is to answer "could I get this site
 * back" answering yes on the strength of a schedule that was not working.
 *
 * This file used to mirror the rule instead of importing it, because
 * get-backups.js pulls in the server fetch layer. The rule now lives in its own
 * module, so these assertions are about the code that actually runs.
 */
const daily = { enabled: true, frequency: "daily" };

test("a schedule whose last run failed is not protection", () => {
  assert.equal(classify(daily, { status: "failed" }), "failing");
});

test("a healthy schedule is protected", () => {
  assert.equal(classify(daily, { status: "verified" }), "protected");
  assert.equal(classify(daily, { status: "running" }), "protected");
  assert.equal(classify(daily, null), "protected", "configured but not yet run");
});

test("paused outranks the last run either way", () => {
  assert.equal(classify({ ...daily, enabled: false }, { status: "failed" }), "paused");
  assert.equal(classify({ enabled: true, frequency: "manual" }, { status: "verified" }), "paused");
});

test("no target at all is unprotected", () => {
  assert.equal(classify(null, { status: "verified" }), "unprotected");
});

test("a failing site counts against the exposed total", () => {
  const rows = [
    { state: classify(daily, { status: "verified" }) },
    { state: classify(daily, { status: "failed" }) },
    { state: classify(null, null) },
  ];
  const total = rows.length;
  const protectedCount = rows.filter((r) => r.state === "protected").length;
  assert.equal(total - protectedCount, 2, "failing and unprotected are both exposed");
});
