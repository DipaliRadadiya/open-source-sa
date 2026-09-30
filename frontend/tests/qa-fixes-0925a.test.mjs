import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (p) => fs.readFileSync(p, "utf8");
const page = () => read("app/(app)/applications/[application]/deployment/page.jsx");
const panel = () => read("components/applications/deployment/deployment-panel.jsx");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const msg = (l) => JSON.parse(read(`messages/${l}.json`));

test("a redeploy does not swap the page for 'still being set up'", () => {
  const src = page();
  assert.match(src, /const settled = isSettled\(application\) \|\| history\.deployments\.length > 0;/);
  // …and the card shows a deploy it did not start.
  assert.match(panel(), /deploying=\{deploying \|\| application\.status === "provisioning" \|\| Boolean\(deployments\[0\]\?\.in_flight\)\}/);
});

test("a deploy this page did not start is announced when it ends", () => {
  const src = panel();
  assert.match(src, /const finished = Boolean\(latest\) && latest\.id === watchingRef\.current && !latest\.in_flight;/);
  assert.match(src, /if \(finished && next\) announce\(next\);/);
  assert.match(src, /if \(Date\.now\(\) - startedAt > DEPLOY_WATCH_LIMIT_MS\)/);
});

test("the root has its own error screen, in the reader's language", () => {
  const src = read("app/global-error.jsx");
  assert.match(src, /import\(`\.\.\/messages\/\$\{chosen\}\.json`\)/);
  assert.match(src, /setCopy\(messages\.default\.errors\)/);
  assert.match(src, /window\.location\.reload\(\)/);
});

test("a failed history read is said, not shown as an empty history", () => {
  assert.match(panel(), /\{history\?\.failed \? \(\s*<LoadFailed description=\{t\("history\.loadFailed"\)\}/);
  for (const l of LOCALES) assert.ok(msg(l).applications.deployment.history.loadFailed, l);
});

test("a disconnected account is shown on the Deploy card with the way back", () => {
  const card = read("components/applications/deployment/deploy-card.jsx");
  assert.match(card, /const unlinked = Boolean\(application\.git_account_missing\);/);
  assert.match(card, /disabled=\{deploying \|\| unlinked \|\| !canManage\}/);
  assert.match(card, /<RelinkGitAccountDialog/);
  assert.match(page(), /application\.git_account_id \|\| application\.git_account_missing/);
});

test("a missing branch is an error beside the field", () => {
  const card = read("components/applications/deployment/deploy-settings-card.jsx");
  assert.match(card, /error=\{notice === "unlinked" \|\| notice === "missing" \? t\(`branchNotice\.\$\{notice\}`\) : undefined\}/);
  for (const l of LOCALES) assert.ok(msg(l).applications.deployment.settings.branchNotice.missing, l);
});

test("a running build log keeps reading; a failed read says so", () => {
  const card = read("components/applications/deployment/deploy-history-card.jsx");
  assert.match(card, /if \(!openId \|\| !openRunning\) return undefined;/);
  assert.match(card, /logFailed \? t\("logFailed"\)/);
});

test("rotating a panel-managed webhook does not tell you to update it yourself", () => {
  const card = read("components/applications/deployment/webhook-card.jsx");
  assert.match(card, /webhook\.registered\s*\? t\("webhook\.rotateConfirmBodyRegistered"/);
  assert.match(card, /const tokenTooShort = typedToken\.length > 0 && typedToken\.length < TOKEN_MIN;/);
  for (const l of LOCALES) {
    const w = msg(l).applications.deployment.webhook;
    assert.ok(w.rotateConfirmBodyRegistered.includes("{provider}") && w.tokenTooShort, l);
  }
});

test("a non-git site is not found, not 'no access'", () => {
  const src = page();
  assert.ok(src.indexOf("if (!isGit) notFound();") < src.indexOf('can(appPermissions, "app_deployment", "view"'));
});

test("the runtime card shows its own error, not the generic one", () => {
  const card = read("components/applications/deployment/runtime-card.jsx");
  assert.doesNotMatch(card, /handleValidationError\(error, form, \(\) =>/);
  assert.match(card, /else toast\.error\(apiMessage\(error, t\("saveFailed"\)\)\);/);
});

test("{php} shows what the deploy substitutes", () => {
  assert.match(read("components/applications/deployment/deploy-settings-card.jsx"), /"\{php\}": application\?\.php_version \? `PHP \$\{application\.php_version\}` : "php"/);
});
