import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { applicationSchema } from "../lib/schemas/application.js";
import { isDeployIncomplete, liveCommit } from "../lib/applications/code-on-disk.js";

const read = (p) => fs.readFileSync(p, "utf8");

test("code_on_disk survives the schema and names the live commit", () => {
  const code = applicationSchema.shape.code_on_disk.parse({ commit: "new", state: "incomplete", message: "m" });
  assert.deepEqual(code, { commit: "new", state: "incomplete", message: "m" });
  // An unknown state is not read as a failure.
  assert.equal(applicationSchema.shape.code_on_disk.parse({ commit: "x", state: "weird" }).state, null);
  assert.equal(liveCommit({ last_commit: "old", code_on_disk: code }), "new");
  assert.equal(isDeployIncomplete({ code_on_disk: code }), true);
  // Without the field (the site list), last_commit is the fallback.
  assert.equal(liveCommit({ last_commit: "old" }), "old");
  assert.equal(liveCommit({ last_commit: { sha: "abc" } }), "abc");
  assert.equal(isDeployIncomplete({ code_on_disk: { state: "deployed" } }), false);
});

test("a deploy that failed after its checkout no longer claims the old version is live", () => {
  for (const f of ["components/applications/deployment/deploy-card.jsx", "components/applications/source-card.jsx"]) {
    const src = read(f);
    assert.match(src, /liveCommit\(application\)/, f);
    assert.match(src, /incomplete \? \(/, f);
    assert.match(src, /application\.code_on_disk\?\.message \|\|/, f);
  }
  assert.match(read("components/applications/deployment/deployment-panel.jsx"), /next\.code_on_disk\?\.state === "incomplete"/);
});

test("webhook: added by the panel shows no paste steps; refused shows the reason above them", () => {
  const src = read("components/applications/deployment/webhook-card.jsx");
  assert.match(src, /\{webhook\.registered \? \(/);
  assert.match(src, /registration\?\.status === "manual" \? \(registration\.message \?\? null\) : null/);
  assert.match(src, /t\("webhook\.added"\)/);
  assert.match(read("lib/schemas/application.js"), /registered: z\.boolean\(\)\.default\(false\)/);
});

test("new strings exist in every locale", () => {
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const a = JSON.parse(read(`messages/${l}.json`)).applications;
    for (const k of ["failedAtStep", "incomplete", "liveCommit", "notFullyDeployed"]) assert.ok(a.deployment.deploy[k], `${l} deploy.${k}`);
    for (const k of ["failedAtStep", "incomplete", "notFullyDeployed"]) assert.ok(a.source[k], `${l} source.${k}`);
    assert.match(a.deployment.webhook.addedBody, /\{provider\}/, l);
    assert.doesNotMatch(a.deployment.webhook.disabledBodyNamed, /next step|siguiente paso|nächsten Schritt/, l);
  }
});
