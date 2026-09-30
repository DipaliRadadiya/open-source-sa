import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (p) => fs.readFileSync(p, "utf8");
const page = read("app/(app)/applications/[application]/page.jsx");
const proc = read("components/applications/process-card.jsx");
const source = read("components/applications/source-card.jsx");

test("RC-1: health checks are only read by a role that can see them", () => {
  assert.match(page, /const canSeeChecks = can\(appPermissions, "app_dashboard", "view", "application"\)/);
  assert.match(page, /const issues = settled && canSeeChecks\s*\?/);
});

test("RC-2: Deploy now stays busy until the refreshed page reports the deploy", () => {
  assert.match(source, /refreshThen\(\(\) => setDeploying\(false\)\)/);
  assert.doesNotMatch(source, /finally \{\s*setDeploying\(false\)/);
  assert.match(source, /catch \(error\) \{[\s\S]*?setDeploying\(false\);/);
});

test("RC-3: 'Running since' only on a running process", () => {
  assert.match(proc, /label: t\("since"\), value: state === "active" \? formatSince\(process\.since, format\) : null/);
});
