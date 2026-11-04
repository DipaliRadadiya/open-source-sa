import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";
import { containerState, describeWarnings, healthWarnings } from "../lib/applications/container-state.js";

// Health badge (backend a2c83ea3e): `container_status` follows the main part; helpers come as
// `health_warnings`. Cases from the task, plus an unknown code and an older API without the field.
const en = JSON.parse(fs.readFileSync(new URL("../messages/en.json", import.meta.url), "utf8")).applications;
const t = (key, values = {}) => {
  const raw = key.split(".").reduce((o, k) => o?.[k], en);
  return String(raw).replace(/\{(\w+)\}/g, (_, k) => values[k] ?? `{${k}}`);
};
t.has = (key) => key.split(".").reduce((o, k) => o?.[k], en) !== undefined;
const app = (container_status, health_warnings) => ({ serving_profile: "docker", status: "active", container_status, health_warnings });

test("running, no warnings: green Running, unchanged", () => {
  assert.equal(containerState(app("running", [])), "running");
});

test("running with a helper warning: yellow, named", () => {
  const a = app("running", [{ service: "functions", state: "unhealthy" }]);
  assert.equal(containerState(a), "degraded");
  assert.equal(describeWarnings(healthWarnings(a), t), "functions (unhealthy)");
  assert.equal(en.containerState.degraded, "Running with warnings");
});

test("main part unhealthy: red, the container is named first", () => {
  const a = app("unhealthy", [{ service: "kong", state: "unhealthy" }]);
  assert.equal(containerState(a), "failed");
  assert.equal(t("markers.mainUnhealthy", { service: "kong", state: t("helperState.unhealthy") }), "Not working: kong (unhealthy)");
});

test("main stopped with a helper problem: red, main first, helper after", () => {
  const a = app("exited", [{ service: "kong", state: "exited" }, { service: "functions", state: "unhealthy" }]);
  assert.equal(containerState(a), "failed");
  assert.equal(t(`helperState.${healthWarnings(a)[0].state}`), "stopped");
  assert.equal(describeWarnings(healthWarnings(a).slice(1), t), "functions (unhealthy)");
});

test("starting stays Starting; exited/unhealthy with no list stay as before", () => {
  assert.equal(containerState(app("starting", [{ service: "storage", state: "created" }])), "starting");
  assert.equal(containerState(app("exited", [])), "failed");
  assert.equal(containerState(app("unhealthy", [])), "failed");
});

test("an unknown future state is shown raw; an older API without the field reads as no warnings", () => {
  assert.equal(describeWarnings([{ service: "worker", state: "oom_killed" }], t), "worker (oom_killed)");
  assert.deepEqual(healthWarnings({ serving_profile: "docker" }), []);
  assert.equal(containerState({ serving_profile: "docker", status: "active", container_status: "running" }), "running");
});

test("the schema keeps health_warnings and survives a malformed one", async () => {
  const src = fs.readFileSync(new URL("../lib/schemas/application.js", import.meta.url), "utf8");
  assert.match(src, /health_warnings: z\.array\(z\.object\(\{ service: z\.string\(\), state: z\.string\(\) \}\)\)\.catch\(\[\]\)/);
});

test("badge, notes, tile and attention lines use them", () => {
  const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
  const badge = read("components/applications/application-status-badge.jsx");
  assert.match(badge, /degraded: \{ variant: "warning", key: "containerState\.degraded" \}/);
  assert.match(badge, /t\("markers\.helperUnhealthy", \{ count: warnings\.length, services: describeWarnings\(warnings, t\) \}\)/);
  assert.match(badge, /t\("markers\.mainUnhealthy", \{/);
  const tiles = read("components/applications/app-status-tiles.jsx");
  assert.match(tiles, /container === "degraded"/);
  assert.match(tiles, /t\("health\.degradedDetail", \{ services: describeWarnings\(warnings, t\) \}\)/);
  const page = read("app/(app)/applications/[application]/page.jsx");
  assert.match(page, /\["degraded", "failed", "restarting"\]\.includes\(containerState\(application\)\) \? healthWarnings\(application\) : \[\]/);
});

// 4 Nov: the backend restarts a Docker app's containers through process/restart.
// Docker apps have no process card, so the ⋯ menu carries it.
test("Docker apps get Restart in the ⋯ menu, greyed with a reason while paused or not running", () => {
  const menu = fs.readFileSync(new URL("../components/applications/application-row-actions.jsx", import.meta.url), "utf8");
  const dialog = fs.readFileSync(new URL("../components/applications/restart-containers-dialog.jsx", import.meta.url), "utf8");
  assert.match(menu, /showRestart = canManage && application\.serving_profile === "docker"/);
  assert.match(menu, /application\.is_disabled\s*\?\s*t\("restart\.pausedHint"\)/);
  assert.match(menu, /application\.status !== "active"\s*\?\s*t\("restart\.notReadyHint"\)/);
  assert.match(menu, /disabled=\{Boolean\(restartHint\)\}/);
  assert.match(dialog, /controlApplicationProcess\(application\.id, "restart"\)/);
  assert.match(dialog, /setError\(apiMessage\(requestError, t\("failed"\)\)\)/);
});
