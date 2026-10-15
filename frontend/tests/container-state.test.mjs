import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import { containerState, hasContainerFailure, portFixFor } from "../lib/applications/container-state.js";
import { applicationSchema } from "../lib/schemas/application.js";

const docker = (over = {}) => ({ serving_profile: "docker", status: "active", container_status: null, last_failure: null, ...over });
const mismatch = { reason: "container_port_mismatch", message: "Nothing answers on container port 8082 — the image listens on 5230.", log: "x", at: "2026-10-07T18:40:00+00:00" };

test("only container sites get a container state", () => {
  assert.equal(containerState({ serving_profile: "php", status: "failed" }), null);
  assert.equal(containerState(null), null);
  // Deployed before the readiness check existed, or a one-click app: nothing to add.
  assert.equal(containerState(docker()), null);
});

test("the four states", () => {
  assert.equal(containerState(docker({ status: "provisioning" })), "starting");
  assert.equal(containerState(docker({ status: "pending" })), "starting");
  assert.equal(containerState(docker({ container_status: "running" })), "running");
  assert.equal(containerState(docker({ container_status: "restarting", last_failure: { reason: "container_restarting" } })), "restarting");
  assert.equal(containerState(docker({ status: "failed", container_status: "not_answering", last_failure: mismatch })), "failed");
  assert.equal(containerState(docker({ container_status: "exited" })), "failed");
});

test("the image's own healthcheck reaches the badge (DS-14)", () => {
  assert.equal(containerState(docker({ container_status: "starting" })), "starting");
  // Live from Docker, so it can be unhealthy with no stored failure.
  assert.equal(containerState(docker({ container_status: "unhealthy" })), "failed");
});

test("an active site with a failure is failed, not Running", () => {
  // PUT /container or Pull failing leaves `status: active` — and a 502.
  const app = docker({ container_status: "not_answering", last_failure: mismatch });
  assert.equal(containerState(app), "failed");
  assert.equal(hasContainerFailure(app), true);
});

test("a retry in progress hides the old failure", () => {
  assert.equal(hasContainerFailure(docker({ status: "provisioning", last_failure: mismatch })), false);
});

test("the one-click port fix only for a mismatch with a known, different port", () => {
  const app = docker({ status: "failed", container_port: 8082, last_failure: mismatch });
  assert.equal(portFixFor(app, 5230), 5230);
  assert.equal(portFixFor(app, null), null);
  assert.equal(portFixFor(app, 8082), null);
  assert.equal(portFixFor(app, 70000), null);
  assert.equal(portFixFor(docker({ last_failure: { reason: "container_exited" } }), 5230), null);
});

test("the schema keeps container_status and last_failure, and survives a bad one", () => {
  const base = { id: 1, name: "memos", domain: "memos.test", site_type: "docker", status: "failed" };
  const parsed = applicationSchema.parse({ ...base, container_status: "not_answering", last_failure: mismatch });
  assert.equal(parsed.container_status, "not_answering");
  assert.equal(parsed.last_failure.reason, "container_port_mismatch");
  // A malformed failure must not take the whole application with it.
  assert.equal(applicationSchema.parse({ ...base, last_failure: "oops" }).last_failure, null);
});

test("every locale has the panel and badge strings with their placeholders", () => {
  for (const file of readdirSync("messages").filter((f) => f.endsWith(".json"))) {
    const a = JSON.parse(readFileSync(`messages/${file}`, "utf8")).applications;
    assert.ok(a.containerState.starting && a.containerState.restarting, file);
    assert.match(a.containerFailure.usePort, /\{port\}/, file);
    assert.match(a.containerFailure.portDialog.descriptionDetected, /\{port\}/, file);
    assert.match(a.containerFailure.checkedAt, /\{time\}/, file);
  }
});

test("create warnings are shown, and the page wires the panel", () => {
  const form = readFileSync("components/applications/create-application-form.jsx", "utf8");
  assert.match(form, /data\?\.warnings/);
  assert.match(form, /toast\.warning\(warning/);
  const page = readFileSync("app/(app)/applications/[application]/page.jsx", "utf8");
  assert.match(page, /<ContainerFailurePanel/);
  assert.match(page, /last_failure\.message/);
  // One Retry: the panel's Redeploy replaces the provisioning card's.
  assert.match(page, /canManage=\{canManage && !containerFailed\}/);
});
