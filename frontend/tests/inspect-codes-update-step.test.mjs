// RC-32 warning_codes and RC-12 step_title (backend fix/backend-small-1105-dipali).
import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { beforeDeployWarnings } from "../lib/docker/inspect-warnings.js";
import { imageInspectResponseSchema } from "../lib/schemas/docker.js";
import { applicationUpdateRunSchema } from "../lib/schemas/application-update.js";

test("with codes, Before you deploy drops exactly what the env rows and Port field say", () => {
  const detected = {
    port_confidence: "none",
    exposed_ports: [],
    env: [{ key: "DB_PASS", required: true }],
    warnings: ["needs DB_PASS", "empty FOO", "no arm64 build", "large", "no port"],
    warning_codes: ["required_env", "empty_env", "no_build", "large", "no_port"],
  };
  assert.deepEqual(beforeDeployWarnings(detected), ["empty FOO", "no arm64 build", "large"]);
  // A code list that does not line up is ignored in favour of the old order rule.
  assert.deepEqual(beforeDeployWarnings({ ...detected, warning_codes: ["required_env"] }), ["empty FOO", "no arm64 build", "large"]);
  assert.deepEqual(beforeDeployWarnings({ ...detected, warnings: ["several ports"], warning_codes: ["several_ports"], env: [] }), []);
});

test("warning_codes is read, and an older answer without it parses", () => {
  const schema = imageInspectResponseSchema;
  assert.ok(schema, "imageInspectResponseSchema is exported");
  assert.deepEqual(schema.parse({ found: true, warnings: ["x"], warning_codes: ["large"] }).warning_codes, ["large"]);
  assert.deepEqual(schema.parse({ found: true, warnings: [] }).warning_codes, []);
});

test("a running update shows the phase it is in now", () => {
  const run = applicationUpdateRunSchema.parse({ id: 4, status: "running", step: "upgrade", step_title: "Running the application's own upgrade" });
  assert.equal(run.step_title, "Running the application's own upgrade");
  const card = readFileSync("components/applications/update-card.jsx", "utf8");
  assert.match(card, /last\?\.status === "running" && last\.step_title \? \(\s*<span className="block font-medium text-foreground">\{last\.step_title\}<\/span>/);
});
