import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

// Users' MERN feedback (3 Nov): what the panel said did not match what it did.
const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const messages = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]));

test("a start command the API refuses is not counted ready, and blocks Create", () => {
  const form = read("components/applications/create-application-form.jsx");
  assert.match(form, /\(config\.name === "start_command" && Boolean\(startCommandProblem\(value\)\)\) \|\|/);
  assert.match(form, /form\.setError\("start_command", \{ type: "manual", message: t\(`form\.startCommand\.\$\{startProblem\}`\) \}\)/);
  // Red, like every other refused value; the server's own message replaces it once set.
  assert.match(form, /isStartCommand && startCommandProblem\(field\.value\) && !form\.formState\.errors\?\.\[config\.name\] \? \(\s*<FormDescription className="flex items-start gap-1\.5 text-destructive">/);
});

test("the review names a choice by its label, not its value ('ssr')", () => {
  const form = read("components/applications/create-application-form.jsx");
  assert.match(form, /config\.options\?\.find\(\(option\) => String\(option\.value\) === String\(value\)\)\?\.label \?\?/);
});

test("a failed version list says which runtime, not 'We could not load applications'", () => {
  const form = read("components/applications/create-application-form.jsx");
  assert.match(form, /t\("form\.runtimeLoadFailed", \{ runtime: config\.source === "php_versions" \? "PHP" : "Node\.js" \}\)/);
  for (const l of LOCALES) assert.match(messages[l].applications.form.runtimeLoadFailed, /\{runtime\}/, l);
});

test("the no-database banner goes once a type that needs no database is chosen", () => {
  const page = read("app/(app)/applications/create/page.jsx");
  const form = read("components/applications/create-application-form.jsx");
  assert.match(page, /databaseNotice=\{\s*noDatabaseEngine\(engines\) \? <NoDatabaseEngineNotice/);
  assert.match(form, /databaseNotice && \(!selected \|\| selected\.needs_database\) \? databaseNotice : null/);
});

test("'All N services running' opens Services and names them", () => {
  const card = read("components/dashboard/server-info-card.jsx");
  const health = read("lib/server/get-service-health.js");
  assert.match(health, /names: services\.map\(\(service\) => service\.label\)/);
  assert.match(card, /<HoverHint content=\{\(health\.names \?\? \[\]\)\.join\(", "\)\}><Link href="\/services" prefetch=\{false\}>/);
});

test("a queued first deploy reads Deploying, not Stopped", () => {
  const tiles = read("components/applications/app-status-tiles.jsx");
  const badge = read("components/applications/application-status-badge.jsx");
  const page = read("app/(app)/applications/[application]/page.jsx");
  assert.match(tiles, /isRedeploying\(application\) \|\| deploy\?\.inFlight/);
  assert.match(badge, /isRedeploying\(application\) \|\| deploying/);
  assert.match(page, /<ApplicationStatusBadge application=\{application\} deploying=\{Boolean\(deployTile\?\.inFlight\)\} \/>/);
});

test("the setup banner names what is left", () => {
  const banner = read("components/setup/setup-banner.jsx");
  const dashboard = read("app/(app)/dashboard/page.jsx");
  assert.match(dashboard, /<SetupBanner remaining=\{setupLeft\.length\} names=\{setupLeft\.map\(\(c\) => c\.title\)\} \/>/);
  assert.match(banner, /format\.list\(names, \{ type: "conjunction" \}\)/);
  for (const l of LOCALES) assert.match(messages[l].setup.bannerBodyNamed, /\{names\}/, l);
});

test("the panel's own 5xx box says it is the panel, not the application", () => {
  assert.equal(messages.en.errors.reason.server.title, "The panel could not load this");
  assert.match(messages.en.errors.reason.server.body, /not in your applications/);
});

test("a generated password typed over is hidden again", () => {
  const form = read("components/applications/create-application-form.jsx");
  assert.match(form, /revealedByGenerate\.current = true;/);
  assert.match(form, /if \(revealedByGenerate\.current\) \{\s*revealedByGenerate\.current = false;\s*setReveal\(false\);/);
});

test("a Git site's queued first deploy is 'deploying' everywhere; one-click sites and failures are not", async () => {
  const { firstDeployPending, isRedeploying } = await import("../lib/applications/settled.js");
  const queued = { status: "active", deployed: true, repository_url: "https://github.com/heroku/node-js-getting-started", last_deployed_at: null, code_on_disk: { commit: null }, has_process: true, process: { state: "inactive" } };
  assert.equal(firstDeployPending(queued), true);
  assert.equal(isRedeploying(queued), true);
  // Deployed once: a stopped process is really stopped.
  assert.equal(firstDeployPending({ ...queued, last_deployed_at: "03-11-2026 12:49:16" }), false);
  // One-click (Uptime Kuma) never has a deploy date; it must still read Stopped when stopped.
  assert.equal(firstDeployPending({ ...queued, repository_url: null, repository: null }), false);
  // A failed first deploy leaves `failed`.
  assert.equal(firstDeployPending({ ...queued, status: "failed" }), false);
  const page = read("app/(app)/applications/[application]/page.jsx");
  assert.match(page, /issues\.issues\.filter\(\(issue\) => issue\.type !== "worker"\)/);
  assert.match(page, /\.\.\.issueItems\(issueList, id,/);
  assert.match(page, /const issueList = firstDeployPending\(application\) \|\| deployInFlight/);
  assert.match(page, /<ProcessCard application=\{application\} canManage=\{canManage\} deploying=\{deployInFlight\} \/>/);
  assert.match(read("lib/dashboard/attention.js"), /!firstDeployPending\(a\)/);
  assert.match(read("components/applications/process-card.jsx"), /: \(deploying \|\| firstDeployPending\(application\)\) && rawState === "inactive"\s*\?\s*"activating"/);
});
