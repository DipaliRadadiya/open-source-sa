import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

// Full-panel QA on the fresh server, 2026-09-29 (reports/fresh/*).
const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");
const locales = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];

test("A3: an install in progress is not listed under 'Needs attention'", () => {
  const src = read("components/setup/setup-checklist.jsx");
  assert.match(src, /const attention = pending\.filter\(\(c\) => c\.state === "failed"\);/);
});

test("A3: setup descriptions wrap instead of being cut to one line", () => {
  assert.doesNotMatch(read("components/setup/setup-component.jsx"), /line-clamp-1 text-xs text-muted-foreground">\{component\.description\}/);
});

test("A4: the login throttle is said on the form, in every locale", () => {
  const src = read("components/forms/login-form.jsx");
  assert.match(src, /status === 429[\s\S]{0,120}form\.setError\("password", \{ message: t\("tooManyAttempts"\) \}\)/);
  for (const l of locales) assert.ok(JSON.parse(read(`messages/${l}.json`)).auth.tooManyAttempts, l);
});

test("A5: unsaved profile edits are guarded beyond the tab switcher", () => {
  assert.match(read("components/account/profile-form.jsx"), /useWatchUnsaved\("account-profile", isDirty\)/);
});

test("A3: setup Install buttons respect each feature's manage permission", () => {
  const page = read("app/(setup)/setup/page.jsx");
  for (const [k, perm] of [["database", "database"], ["fail2ban", "fail2ban"], ["php", "php"], ["node", "node"], ["build_tools", "node"]])
    assert.match(page, new RegExp(`${k}: can\\(permissions, "${perm}", "manage"\\)`));
  assert.match(read("components/setup/setup-component.jsx"), /const blocked = busy \|\| locked \|\| denied;/);
  for (const l of locales) assert.ok(JSON.parse(read(`messages/${l}.json`)).setup.installNotPermitted, l);
});

test("A4: an expired session on a client-side navigation goes to sign-in, not 'no access'", () => {
  const src = read("lib/permissions/get-permissions.js");
  assert.match(src, /if \(res\.status === 401 \|\| res\.status === 419\) redirect\(await signedOutPath\(\)\);/);
});

test("A3: the recommended database engine is labelled in words and pre-selected", () => {
  const src = read("components/setup/database-options.jsx");
  assert.match(src, /installable\.find\(\(o\) => o\.recommended\)\?\.value/);
  assert.match(src, /option\.recommended && !unavailable \? \(\s*<Badge/);
});

test("A4: a failed logout says so instead of bouncing back through /login", () => {
  const src = read("components/sections/user-menu.jsx");
  assert.match(src, /status !== 401 && status !== 419[\s\S]{0,80}toast\.error\(apiMessage\(error, t\("logOutFailed"\)\)\)/);
  for (const l of locales) assert.ok(JSON.parse(read(`messages/${l}.json`)).common.logOutFailed, l);
});

test("A3: the setup banner reads storage without a hydration mismatch", () => {
  const src = read("components/setup/setup-banner.jsx");
  assert.match(src, /useSyncExternalStore\(subscribeStorage, readDismissed, \(\) => true\)/);
  assert.doesNotMatch(src, /useState\(\(\) => \{\s*if \(typeof window/);
});

test("B1: git package-manager templates survive the site-types schema", async () => {
  const { siteTypesResponseSchema } = await import("../lib/schemas/application.js");
  const parsed = siteTypesResponseSchema.parse({ site_types: [{ name: "git", title: "Git", fields: [
    { name: "package_manager", label: "PM", build_templates: { npm: "npm ci\nnpm run build" } },
    { name: "x", label: "X", build_templates: [] },
  ] }] });
  assert.equal(parsed.site_types[0].fields[0].build_templates.npm, "npm ci\nnpm run build");
  assert.deepEqual(parsed.site_types[0].fields[1].build_templates, {});
});

test("B1: build_command is multi-line and follows the package manager until edited", () => {
  const src = read("components/applications/create-application-form.jsx");
  assert.match(src, /config\.type === "textarea" \|\| config\.name === "build_command"/);
  assert.match(src, /if \(current && !Object\.values\(templates\)\.includes\(current\)\) return;/);
});

test("B1: the fix-it links only follow blocked cards that are on screen", () => {
  assert.match(read("components/applications/site-type-picker.jsx"), /for \(const type of filtered\) \{[\s\S]{0,80}if \(type\.available\) continue;/);
});

test("B2: delete asks to drop databases only when it listed them, and announces with the list", () => {
  const src = read("components/applications/delete-application-dialog.jsx");
  assert.match(src, /removeDatabases: databases\.length > 0 && removeDatabases/);
  assert.match(src, /refreshThen\(done\)/);
});

test("B1: a failed step is not also drawn as done", () => {
  assert.match(read("components/applications/step-list.jsx"), /failedStep && steps\[steps\.length - 1\] === failedStep \? steps\.slice\(0, -1\) : steps/);
});

test("B1: every step the server records has a label", () => {
  const steps = JSON.parse(read("messages/en.json")).applications.details.steps;
  for (const k of ["ensure_account", "build", "create_admin", "schedule_cron", "start_app", "verify_install", "verify_serving"]) assert.ok(steps[k], k);
});

test("B1/B2: retry stays busy until the page shows the new run", () => {
  assert.match(read("components/applications/provisioning-card.jsx"), /refreshThen\(\(\) => setRetrying\(false\)\)/);
  assert.match(read("components/applications/application-row-actions.jsx"), /refreshThen\(\(\) => setRetrying\(false\)\)/);
});

test("B1: view-only sees Create disabled with a reason, and the create page says why", () => {
  assert.match(read("components/applications/applications-table.jsx"), /<ReasonTooltip reason=\{t\("noPermission"\)\}>/);
  assert.match(read("app/(app)/applications/create/page.jsx"), /<PermissionDenied title=\{t\("createTitle"\)\} description=\{t\("noPermission"\)\} \/>/);
});

test("B1: the name cap matches the API", () => {
  assert.match(read("lib/schemas/application.js"), /\.max\(240, "max240"\)/);
});

test("B2: an invalid filter or sort in the URL falls back to the plain list", () => {
  assert.match(read("app/(app)/applications/page.jsx"), /result\.status === 422 && \(sp\?\.status \|\| sp\?\.site_type \|\| sp\?\.sort\)/);
});

test("B2: a Node git app shows no PHP version", async () => {
  const { phpVersionShown } = await import("../lib/applications/php-version-shown.js");
  assert.equal(phpVersionShown({ site_type: "git", rendering_type: "ssr", php_version: "8.4" }), null);
  assert.equal(phpVersionShown({ site_type: "git", rendering_type: "php", php_version: "8.4" }), "8.4");
  assert.equal(phpVersionShown({ site_type: "wordpress", php_version: "8.3" }), "8.3");
});

test("B3: stopping a process announces once the list no longer shows it; view-only reason opens on tap", () => {
  const src = read("components/dashboard/kill-process-button.jsx");
  assert.match(src, /refreshThen\(\(\) => \{\s*toast\.success\(t\("kill\.stopped"/);
  assert.match(src, /<ReasonTooltip reason=\{t\("kill\.noPermission"\)\}>/);
});

test("B3: the attention chip counts applications, not findings", () => {
  assert.match(read("components/dashboard/site-attention.jsx"), /new Set\(findings\.map\(\(finding\) => finding\.site\)\)\.size/);
});

test("Resume closes the ⋯ menu once the page shows the application running", () => {
  assert.match(read("components/applications/application-row-actions.jsx"), /toast\.success\(t\("pause\.resumed"[\s\S]{0,80}setResuming\(false\);\s*setMenuOpen\(false\);/);
});

test("Lock the site folder: path, effects and reassurance are separate, readable parts", () => {
  const src = read("components/applications/root-lock-button.jsx");
  for (const k of ["intro", "folderLabel", "effectOwner", "effectNoChanges", "effectPublic"]) assert.match(src, new RegExp(`t\\("${k}"\\)`), k);
  for (const l of locales) { const r = JSON.parse(read(`messages/${l}.json`)).applications.rootLock; assert.ok(r.effectPublic && !r.body, l); }
});

test("An uploaded certificate re-reads the page so the Domains tab shows it", () => {
  assert.match(read("components/applications/domains/ssl-section.jsx"), /onIssued=\{\(next\) => \{\s*setCert\(next\);[\s\S]{0,300}if \(!isPending\(next\)\) router\.refresh\(\);/);
});

test("A git application shows its Git host's mark on the dashboard and the sidebar, as in the list", () => {
  assert.match(read("app/(app)/applications/[application]/page.jsx"), /<SiteTypeLogo[\s\S]{0,80}provider=\{gitProviderFor\(application, providersByAccountId\(gitAccounts\)\)\}/);
  assert.match(read("app/(app)/applications/[application]/layout.jsx"), /<ApplicationNav [^>]*gitProvider=\{gitProvider\}/);
  assert.match(read("components/sections/app-sidebar.jsx"), /<SiteTypeLogo name=\{application\.site_type\} provider=\{gitProvider\}/);
});

test("Application skeletons above a section draw that section, not their own page", () => {
  const dir = "app/(app)/applications/[application]";
  const resolver = read("components/applications/skeletons/application-route-skeleton.jsx");
  for (const entry of fs.readdirSync(path.join(root, dir), { withFileTypes: true })) {
    if (!entry.isDirectory() || !fs.existsSync(path.join(root, dir, entry.name, "loading.jsx"))) continue;
    assert.match(resolver, new RegExp(`"${entry.name}": \\w+Skeleton`), entry.name);
  }
  assert.match(read(`${dir}/loading.jsx`), /<ApplicationRouteSkeleton /);
  assert.match(read("app/(app)/applications/loading.jsx"), /<ApplicationRouteSkeleton fallback=\{<ListSkeleton \/>\} \/>/);
});

test("The sidebar logo asks before leaving unsaved changes, like every other sidebar link", () => {
  assert.match(read("components/sections/app-sidebar.jsx"), /href="\/dashboard"[\s\S]{0,120}guardNavigation\("\/dashboard"\)\) event\.preventDefault\(\);/);
});
