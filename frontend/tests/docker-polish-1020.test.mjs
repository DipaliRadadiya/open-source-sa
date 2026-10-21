import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

// Krishna, 20 Oct.
test("the Docker page names the application a network or volume belongs to", () => {
  const panel = read("components/docker/docker-resources-panel.jsx");
  assert.match(panel, /const appName = \(id\) => applicationNames\[id\] \?\? t\("ownedByApp", \{ id \}\);/);
  assert.match(panel, /appName\(network\.application_id\)/);
  assert.match(panel, /appName\(volume\.application_id\)/);
  assert.match(read("app/(app)/docker/page.jsx"), /applicationNames=\{applicationNames\}/);
});

test("a Docker-stack server's setup page lists Docker as installed", () => {
  const checklist = read("components/setup/setup-checklist.jsx");
  assert.match(checklist, /setup\.stack === "docker" && !setup\.components\.some\(\(c\) => c\.key === "docker"\)/);
  assert.match(read("components/setup/component-meta.js"), /docker: Container,/);
});

test("the first-run credentials card goes the moment the dismiss succeeds", () => {
  const card = read("components/applications/first-run-credentials.jsx");
  assert.match(card, /await acknowledgeContainerSecrets\(application\.id\);\s*setDismissed\(true\);/);
  assert.match(card, /if \(keys\.length === 0 \|\| dismissed\) return null;/);
});

// Krishna, 21 Oct: the panel rewrites compose.yml from its own copy on every apply, so
// an edit in Files looks saved and is lost. Docker apps get a pointer to Compose File.
test("a Docker app's Files page points compose edits to the Compose File page", () => {
  const page = read("app/(app)/applications/[application]/files/page.jsx");
  assert.match(page, /settled && application\.serving_profile === "docker" \? \(/);
  assert.match(page, /t\.rich\("dockerNote"/);
  assert.match(page, /href=\{`\/applications\/\$\{id\}\/compose`\}/);
});
