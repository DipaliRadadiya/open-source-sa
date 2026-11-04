// 4 Nov: Pull and redeploy answers 202 and runs on the queue (backend D5). The page
// must keep saying "Redeploying" until the run ends, hold what the API refuses
// meanwhile, and list the two new job kinds.
import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { containerRedeployFailure, containerRedeployInFlight, isRedeploying } from "../lib/applications/settled.js";
import { applicationSchema } from "../lib/schemas/application.js";
import { operationHref } from "../lib/operations/href.js";

const src = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
const docker = (redeploy) => ({ serving_profile: "docker", status: "active", last_deployed_at: null, container_redeploy: redeploy });
const run = (over = {}) => ({ id: 7, kind: "container_redeploy", status: "running", in_flight: true, failed_step: null, failed_reason: null, failed_reason_title: null, reference: null, ...over });

test("a queued or running redeploy reads as redeploying while status stays active", () => {
  assert.equal(containerRedeployInFlight(docker(run())), true);
  assert.equal(isRedeploying(docker(run({ status: "queued" }))), true);
  assert.equal(isRedeploying(docker(run({ status: "succeeded", in_flight: false }))), false);
  assert.equal(isRedeploying(docker(null)), false);
});

test("the schema keeps container_redeploy, and a malformed one becomes null rather than failing the app", () => {
  const field = applicationSchema.shape.container_redeploy;
  const kept = field.parse(run({ status: "failed", in_flight: false, failed_reason_title: "The image could not be pulled.", reference: "R-1" }));
  assert.equal(kept.failed_reason_title, "The image could not be pulled.");
  assert.equal(kept.reference, "R-1");
  assert.equal(kept.in_flight, false);
  assert.equal(field.parse("nope"), null);
  assert.equal(field.parse(undefined), undefined);
});

test("a failed pull is worded even when the API names no reason", () => {
  assert.equal(containerRedeployFailure(run({ failed_step: "image_pull" }), "The image could not be pulled."), "The image could not be pulled.");
  assert.equal(containerRedeployFailure(run({ failed_step: "image_pull", failed_reason_title: "Docker Hub refused the login." }), "x"), "Docker Hub refused the login.");
  assert.equal(containerRedeployFailure(run({ failed_step: "container_start" }), "x"), null);
});

test("Background jobs link an install to the app and a redeploy to its Container page", () => {
  const subject = { type: "application", id: 5, name: "supa" };
  assert.equal(operationHref({ kind: "application_install", subject }, { canOpenApplications: true }), "/applications/5");
  assert.equal(operationHref({ kind: "container_redeploy", subject }, { canOpenApplications: true }), "/applications/5/container");
  assert.equal(operationHref({ kind: "container_redeploy", subject }, { canOpenApplications: false }), null);
  const en = JSON.parse(src("messages/en.json"));
  assert.equal(en.operations.kinds.application_install, "Application install");
  assert.equal(en.operations.kinds.container_redeploy, "Pull and redeploy");
});

test("the watcher reports how a run it saw in flight ended, and only that run", () => {
  const watcher = src("components/applications/application-status-watcher.jsx");
  assert.match(watcher, /const redeployId = redeploy\?\.in_flight \? redeploy\.id : null;/);
  assert.match(watcher, /if \(next\?\.id !== redeployId\) return;/);
  assert.match(watcher, /toast\.error\(containerRedeployFailure\(next, tc\("pullFailed"\)\) \?\? t\("failed"\)/);
  assert.match(src("app/(app)/applications/[application]/layout.jsx"), /redeploy=\{result\.application\.container_redeploy \?\? null\}/);
});

test("Pull says it started, not that it finished, and the button stays busy from the server's state", () => {
  const card = src("components/applications/container-card.jsx");
  // 200 from an API without the queue still means done, so this half can ship first.
  assert.match(card, /toast\.success\(res\.status === 202 \? tr\("started"\) : t\("pulled"\)\)/);
  assert.match(card, /disabled=\{pulling \|\| redeploying\}/);
  assert.equal((card.match(/blockedReason=\{redeploying \? tr\("busy"\) : null\}/g) ?? []).length, 3);
  assert.match(src("components/applications/container-failure-panel.jsx"), /toast\.success\(res\.status === 202 \? t\("queued"\) : t\("fixed"\)\)/);
});

test("what the API refuses during a redeploy is greyed with the reason", () => {
  const menu = src("components/applications/application-row-actions.jsx");
  assert.match(menu, /: redeploying\s*\? t\("redeploy\.busy"\)/);
  assert.match(menu, /<MenuItemHint hint=\{redeploying \? t\("redeploy\.busy"\) : null\}>/);
  assert.match(src("components/applications/compose-editor.jsx"), /: redeploying\s*\? tr\("busy"\)/);
});
