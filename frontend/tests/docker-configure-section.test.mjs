import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { beforeDeployWarnings } from "../lib/docker/inspect-warnings.js";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

// ImageInspector order: required env, empty env, no build, large, then the port note.
test("Before you deploy drops what the Port field and env rows already say", () => {
  const unknownPort = { port_confidence: "none", exposed_ports: [], env: [], warnings: ["large", "no port"] };
  assert.deepEqual(beforeDeployWarnings(unknownPort), ["large"]);
  const severalPorts = { port_confidence: "declared", exposed_ports: [80, 443], env: [], warnings: ["several ports"] };
  assert.deepEqual(beforeDeployWarnings(severalPorts), []);
  const requiredEnv = { port_confidence: "declared", exposed_ports: [80], env: [{ key: "DB_PASS", required: true }], warnings: ["needs DB_PASS", "no arm64 build"] };
  assert.deepEqual(beforeDeployWarnings(requiredEnv), ["no arm64 build"]);
  assert.deepEqual(beforeDeployWarnings(null), []);
});

test("compose mode: the file first and required, registry folded, no mode notes", () => {
  const form = read("components/applications/create-application-form.jsx");
  assert.match(form, /<DockerComposeSetup form=\{form\} registryOptions=\{registryOptions\} \/>/);
  assert.match(form, /isDocker && !isDockerSimple && config\.name === "compose"\s*\? \{ \.\.\.config, required: true \}/);
  assert.doesNotMatch(form, /dockerImage\.composeMode|dockerImage\.useImage/);
  const compose = read("components/applications/docker-compose-setup.jsx");
  assert.ok(compose.indexOf('name="compose"') < compose.indexOf("<DockerRegistryPicker"));
});

test("image mode: one card of fact rows, a version box wide enough for its badge, no compose link", () => {
  const setup = read("components/applications/docker-image-setup.jsx");
  assert.match(setup, /<div className="@container divide-y rounded-lg border">/);
  assert.match(setup, /<div className="grid gap-4 @2xl:grid-cols-2">\s*<div data-field-name="image_tag"/);
  assert.match(setup, /<SelectTrigger id=\{versionId\} className="w-full">/);
  // The loading placeholder is the same width as the field it stands in for.
  assert.match(setup, /<Skeleton className="h-9 w-full rounded-lg" \/>/);
  assert.match(setup, /className="order-2 text-destructive hover:bg-destructive\/10 hover:text-destructive sm:order-3"/);
  assert.match(setup, /beforeDeploy\.map\(\(warning\) =>/);
  assert.doesNotMatch(setup, /useCompose|composeLead/);
});
