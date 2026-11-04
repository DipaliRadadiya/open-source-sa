import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

// Users' Docker-stack feedback (3 Nov): frontend half.
const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const messages = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]));
const each = (pick) => LOCALES.forEach((l) => assert.ok(pick(messages[l]), l));

test("install progress: no sweeping bar that reads as a percentage; it says the install keeps going", () => {
  const card = read("components/applications/provisioning-card.jsx");
  assert.doesNotMatch(card, /<Progress indeterminate/);
  assert.match(card, /\{t\("keepsGoing"\)\}/);
  each((m) => m.applications.details.keepsGoing);
});

test("Pull and redeploy says what it is doing while the request runs", () => {
  const card = read("components/applications/container-card.jsx");
  // 4 Nov: the redeploy runs on the queue, so the words follow the server's state too.
  assert.match(card, /\{pulling \|\| redeploying \? t\("pulling"\) : t\("pull"\)\}/);
  assert.match(card, /\{pulling \|\| redeploying \? \(\s*<p role="status" className="mt-2 text-xs text-muted-foreground">\s*\{tr\("runningHint"\)\}/);
  each((m) => m.applications.container.pulling && m.applications.redeploy.runningHint);
});

test("the create form says HTTPS turns on by itself", () => {
  assert.match(read("components/applications/create-application-form.jsx"), /\{temporary \? t\("form\.temporaryDomainHint"\) : t\("form\.domainHint"\)\}\{" "\}\s*\{t\("form\.httpsAutomatic"\)\}/);
  each((m) => m.applications.form.httpsAutomatic);
});

test("the Last backup cell has no hidden ': Set up backups' to leak into its tooltip", () => {
  assert.doesNotMatch(read("components/applications/applications-table.jsx"), /sr-only">: /);
});

test("'N of M on' names the protections that are on", () => {
  const card = read("components/applications/protection-card.jsx");
  assert.match(card, /t\("summaryNamed", \{ on, total: items\.length, names: format\.list\(onItems\.map\(\(item\) => item\.label\), \{ type: "conjunction" \}\) \}\)/);
  each((m) => /\{names\}/.test(m.applications.protection.summaryNamed));
});

test("the restart banner and its confirm say to let running installs finish", () => {
  const banner = read("components/sections/reboot-required-banner.jsx");
  assert.match(banner, /\{t\("waitForInstalls"\)\}/);
  assert.match(banner, /description=\{`\$\{t\("confirmDescription"\)\} \$\{t\("waitForInstalls"\)\}`\}/);
  each((m) => m.rebootBanner.waitForInstalls);
});

test("Databases on a Docker server says why it is not there", () => {
  const denied = read("components/sections/permission-denied.jsx");
  assert.match(denied, /feature === "database" && Array\.isArray\(profiles\) && profiles\.includes\("docker"\)/);
  assert.match(denied, /description=\{reason\}/);
  each((m) => m.common.permissionDenied.unavailableDatabaseDocker);
});
