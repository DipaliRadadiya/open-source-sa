import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const page = read("app/(app)/applications/[application]/page.jsx");
const proc = read("components/applications/process-card.jsx");
const source = read("components/applications/source-card.jsx");
const backup = read("components/applications/backup-card.jsx");
const actions = read("components/applications/application-row-actions.jsx");

test("AD-A: the header actions wrap on a phone", () => {
  assert.match(page, /<div className="flex flex-wrap items-center gap-2">\s*\{\/\* Outline, not filled/);
});

test("AD-B: Security labels wrap instead of being cut off", () => {
  assert.doesNotMatch(read("components/applications/protection-card.jsx"), /flex-1 truncate text-sm font-medium/);
});

test("AD-C / AD-I: Stop asks first; buttons follow the process state", () => {
  assert.match(proc, /action === "stop" \? setConfirmStop\(true\) : run\(action\)/);
  assert.match(proc, /<ConfirmDialog[\s\S]*onConfirm=\{\(\) => run\("stop"\)\}/);
  assert.match(proc, /action: "start", icon: Play, reason: state === "active"/);
});

test("AD-D: Deploy now waits for a running deploy", () => {
  assert.match(page, /deployInFlight=\{Boolean\(latestDeploy\.latest\?\.in_flight\)\}/);
  assert.match(source, /disabled=\{deploying \|\| deployInFlight\}/);
  assert.match(source, /deployInFlight \? <AutoRefresh/);
});

test("AD-E: failed server checks are not an all-clear", () => {
  assert.match(page, /issues\.failed && \{ key: "checks", label: t\("attention\.checksFailed"\) \}/);
});

test("AD-F / AD-N: backup card", () => {
  assert.match(page, /noneKept=\{!backupRuns\.failed && backupRuns\.meta\?\.total === /);
  assert.match(backup, /noneKept \? t\("noneKept"\)/);
  assert.match(backup, /target \|\| failed \? t\("manage"\) : t\("setUp"\)/);
});

test("AD-G: pause and resume speak after the refresh", () => {
  assert.match(read("components/applications/pause-application-dialog.jsx"), /refreshThen\(\(\) => \{\s*toast\.success\(t\("paused"/);
  assert.match(actions, /refreshThen\(\(\) => \{\s*toast\.success\(t\("pause\.resumed"/);
});

test("AD-H / AD-K: a deliberate stop is 'stopped'; since is formatted", () => {
  assert.match(proc, /stoppedHere && rawState === "failed" \? "inactive" : rawState/);
  assert.match(proc, /formatSince\(process\.since, format\)/);
});

test("AD-J: PHP version only for a PHP-served site", () => {
  assert.match(read("components/applications/site-facts-card.jsx"), /application\.serving_profile === "php" \? application\.php_version : null/);
});

test("AD-L: closing the ⋯ menu keeps focus unless a dialog opens", () => {
  assert.match(actions, /if \(!openingDialog\.current\) return;/);
});

test("AD-M: view-only is not sent to controls it cannot use", () => {
  assert.match(page, /\.\.\.\(canManage \? \{ action: t\("attention\.reviewFolder"\), href: "#security" \} : null\)/);
  assert.match(source, /\{canSeeDeployment \? \(/);
});

test("new strings exist in every locale", () => {
  for (const l of LOCALES) {
    const a = JSON.parse(read(`messages/${l}.json`)).applications;
    for (const [ns, k] of [["attention", "checksFailed"], ["backups", "noneKept"], ["source", "inFlightReason"], ["source", "inFlightNote"], ["process", "stopTitle"], ["process", "stopBody"], ["process", "stopping"], ["process", "alreadyRunning"], ["process", "notRunning"], ["process", "cancel"]]) assert.ok(a[ns][k], `${l} ${ns}.${k}`);
  }
});

test("SSL: an automatic certificate on its way is not 'SSL not set up'", () => {
  const card = read("components/applications/domains-card.jsx");
  assert.match(card, /const issuing = certificate\?\.status === "pending" \|\| certificate\?\.status === "issuing";/);
  assert.match(card, /issuing \? <AutoRefresh/);
  assert.match(page, /!secured && !certificateIssuing && \{/);
});

test("Runtimes: database engines are listed once, from the databases API", () => {
  const info = read("components/dashboard/server-info-card.jsx");
  assert.match(info, /const DATABASE_ENGINES = new Set\(\["mysql", "mariadb", "mongodb", "postgresql"\]\);/);
  assert.match(info, /!DATABASE_ENGINES\.has\(name\)/);
});

test("Logs: clearing a log re-reads the list so its size is current", () => {
  assert.match(read("components/logs/logs-panel.jsx"), /toast\.success\(t\("clearDone"[\s\S]{0,300}reloadSources\(\);/);
});

test("RP-1: the SSL tab takes the certificate the page re-reads", () => {
  assert.match(read("components/applications/domains/ssl-section.jsx"), /if \(certFrom !== initialCertificate\) \{\s*setCertFrom\(initialCertificate\);\s*setCert\(initialCertificate\);/);
});

test("RP-2: a running backup is not a kept one", () => {
  assert.match(read("components/applications/backups/backups-panel.jsx"), /noneKept=\{!backupsFailed && total - backups\.filter\(\(b\) => BACKUP_IN_FLIGHT\.includes\(b\.status\)\)\.length === 0\}/);
  assert.match(page, /backupRuns\.meta\?\.total === backupRuns\.backups\.filter\(\(b\) => BACKUP_IN_FLIGHT\.includes\(b\.status\)\)\.length/);
});

test("RP-3: an unsaved PHP value shows as saved → new", () => {
  const php = read("components/applications/php/php-panel.jsx");
  assert.match(php, /function Stat\(\{ icon: Icon, label, value, saved = value, unsavedLabel \}\)/);
  assert.match(php, /value=\{memoryLimit\} saved=\{defaults\.memory_limit\}/);
});

test("RP-4: the firewall's caught list keeps itself current while watching", () => {
  assert.match(read("app/(app)/applications/[application]/firewall/page.jsx"), /<AutoRefresh intervalMs=\{30000\} stopAfterMs=\{600000\} \/>\s*<DetectLogCard/);
});

test("RP-5: the Applications list re-reads when the reader comes back", () => {
  assert.match(read("app/(app)/applications/page.jsx"), /<RefreshOnReturn \/>/);
  assert.match(read("components/ui/refresh-on-return.jsx"), /Date\.now\(\) - hiddenAt\.current >= afterMs\) router\.refresh\(\)/);
});
