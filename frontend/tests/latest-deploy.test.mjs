import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { isNewPush, latestChanged, watchDelay, WATCH_BUSY_MS, WATCH_MS } from "../lib/applications/latest-deploy.js";
import { latestDeploymentResponseSchema } from "../lib/schemas/deploy-history.js";

const top = { id: 12, status: "succeeded", in_flight: false, trigger: "manual" };

test("a newer run, or the top row changing status, is news; the same row is not", () => {
  assert.equal(latestChanged({ ...top }, top), false);
  assert.equal(latestChanged({ ...top, id: 13, status: "running" }, top), true);
  assert.equal(latestChanged({ ...top, status: "failed" }, top), true);
  assert.equal(latestChanged(null, top), false);
  assert.equal(latestChanged({ ...top }, null), true);
});

test("only a new run started by a push gets the toast", () => {
  assert.equal(isNewPush({ ...top, id: 13, trigger: "webhook" }, top), true);
  assert.equal(isNewPush({ ...top, id: 13, trigger: "manual" }, top), false);
  assert.equal(isNewPush({ ...top, trigger: "webhook" }, top), false);
});

test("checks every 5s, every 2.5s while a deploy runs", () => {
  assert.equal(watchDelay({ in_flight: false }, false), WATCH_MS);
  assert.equal(watchDelay({ in_flight: true }, false), WATCH_BUSY_MS);
  assert.equal(watchDelay(null, true), WATCH_BUSY_MS);
  assert.equal(WATCH_MS, 5000);
  assert.equal(WATCH_BUSY_MS, 2500);
});

test("{ latest: null } for a site that never deployed parses", () => {
  assert.equal(latestDeploymentResponseSchema.parse({ latest: null }).latest, null);
});

test("the panel pauses while the tab is hidden and asks again on return", () => {
  const src = fs.readFileSync("components/applications/deployment/deployment-panel.jsx", "utf8");
  assert.match(src, /if \(document\.hidden\) return;/);
  assert.match(src, /document\.addEventListener\("visibilitychange", onVisibility\)/);
  assert.match(src, /fetchLatestDeployment\(application\.id\)/);
  assert.match(src, /t\("history\.pushStarted"/);
});
