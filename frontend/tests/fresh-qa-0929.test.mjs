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

test("Bulk dialogs keep the paths they opened with, so a move that empties the list does not crash the page", () => {
  const src = read("components/applications/files/bulk-dialogs.jsx");
  assert.match(src, /paths: selectedPaths[\s\S]{0,400}const \[paths\] = useState\(selectedPaths\);/);
});

test("Saving a file returns focus to its row without the focus ring", () => {
  const src = read("components/applications/files/file-editor-dialog.jsx");
  assert.match(src, /saved\.current = true;\s*onOpenChange\?\.\(false\);/);
  assert.match(src, /onCloseAutoFocus=\{\(event\) => \{\s*if \(!saved\.current\) return;\s*event\.preventDefault\(\);\s*opener\?\.focus\?\.\(\{ preventScroll: true, focusVisible: false \}\);/);
});

test("Creating or removing a staging copy keeps the dialog open until the page shows the result", () => {
  // Driven by the data, not the refresh timing: the dialog lives in the state
  // it changes, so it goes when that state does (20 s fallback).
  const create = read("components/applications/staging/create-staging-dialog.jsx");
  assert.match(create, /await createApplicationStaging\(appId, values\.domain\);[\s\S]{0,500}router\.refresh\(\);\s*setAwaitingPage\(true\);/);
  assert.doesNotMatch(create, /refreshThen/);
  assert.match(read("components/applications/staging/staging-panel.jsx"), /<DeleteApplicationDialog [^>]*closeWhenGone \/>/);
  assert.match(read("components/applications/delete-application-dialog.jsx"), /if \(closeWhenGone\) \{\s*say\(\);\s*router\.refresh\(\);\s*setAwaitingPage\(true\);/);
});

test("Worker form errors, from the form or the server, are scrolled into view", () => {
  for (const f of ["create-worker-dialog.jsx", "edit-worker-dialog.jsx"]) {
    const src = read(`components/applications/workers/${f}`);
    assert.equal((src.match(/scrollToFirstError\(\);/g) ?? []).length, 2, f);
    assert.match(src, /role="alert"\s*data-form-error/, f);
  }
  assert.match(read("lib/forms/scroll-to-first-error.js"), /querySelector\('\[data-form-error\], \[aria-invalid="true"\]'\)/);
});

test("Starting a process reads as starting until the page has the new state, never as failed", () => {
  const src = read("components/applications/process-card.jsx");
  assert.match(src, /expected === "running" && rawState !== "active"\s*\?\s*"activating"/);
  assert.match(src, /refreshThen\(\(\) => \{\s*toast\.success\(t\(DONE_KEY\[action\]\)\);/);
});

test("View-only File Manager roles cannot open, download or preview files (backend d91f2b41)", () => {
  const dir = "components/applications/files";
  assert.match(read(`${dir}/files-table.jsx`), /if \(!canManage \|\| !canOpenFile\(file\.name\)\)/);
  assert.match(read(`${dir}/files-cards.jsx`), /!canManage \|\| !canOpenFile\(file\.name\)/);
  assert.match(read(`${dir}/site-search-results.jsx`), /const openable = canManage && /);
  assert.match(read(`${dir}/files-panel.jsx`), /openedFile && canManage && canOpenFile/);
  assert.match(read(`${dir}/file-shortcuts.jsx`), /disabled=\{!canManage\}/);
  assert.match(read(`${dir}/file-thumb.jsx`), /const thumbnail = canPreview && /);
  for (const f of ["file-row-actions.jsx", "file-actions-menu.jsx"]) {
    assert.match(read(`${dir}/${f}`), /downloadReason = symlinkReason \?\? \(canManage \? null : t\("noPermission"\)\)/, f);
  }
  // A blocked download is a real disabled button, not a link with `disabled`.
  assert.match(read(`${dir}/file-row-actions.jsx`), /\{downloadReason \? \(\s*<Button[^>]*disabled/);
});

test("SSH keys: one red click removes a key; no second Remove/Cancel step", () => {
  const src = read("components/system-users/ssh-keys-dialog.jsx");
  assert.match(src, /className="size-8 shrink-0 text-destructive hover:bg-destructive\/10 hover:text-destructive"\s*onClick=\{\(\) => onRemove\(key\.id\)\}/);
  assert.doesNotMatch(src, /removeConfirm/);
  for (const l of locales) assert.equal(JSON.parse(read(`messages/${l}.json`)).systemUsers.sshForm.removeConfirm, undefined, l);
});

test("No-login shell turns SSH access off and locks it on Create System User", () => {
  const src = read("components/system-users/create-system-user-dialog.jsx");
  assert.match(src, /const noLoginShell = chosenShellEntry\?\.allows_login === false;/);
  assert.match(src, /if \(noLoginShell && form\.getValues\("ssh_access"\)\) \{\s*form\.setValue\("ssh_access", false/);
  assert.match(src, /locked: sshViaSudo \|\| noLoginShell/);
});

test("The SSH-not-enforced notice is one soft row with its action at the end", () => {
  const caution = read("components/ui/caution.jsx");
  assert.match(caution, /\{action \? <div className="shrink-0">\{action\}<\/div> : null\}/);
  // flex-1 beside a shrink-0 button squeezed the text to a word per line on a phone.
  assert.match(caution, /action \? "flex-wrap items-center" : "items-start"/);
  assert.match(caution, /action \? "min-w-48" : "min-w-0"/);
  assert.match(read("components/system-users/system-users-table.jsx"), /<Caution\s+size="md"\s+action=\{/);
});

test("File delete starts with Permanently delete ticked, single and bulk", () => {
  const single = read("components/applications/files/delete-file-dialog.jsx");
  assert.match(single, /const \[permanent, setPermanent\] = useState\(true\);/);
  assert.match(single, /if \(next\) setPermanent\(true\);/);
  assert.match(read("components/applications/files/bulk-dialogs.jsx"), /const \[permanent, setPermanent\] = useState\(true\);/);
});

test("View-only database and system-user roles: withheld secrets read as withheld, phpMyAdmin hidden (backend 30ef82b9)", () => {
  assert.match(read("components/databases/phpmyadmin-button.jsx"), /if \(state === "hidden" \|\| !canManage\) return null;/);
  assert.match(read("components/databases/connection-details.jsx"), /withheld: !canManage && !user\.password && user\.password_known !== false/);
  assert.match(read("components/databases/database-users.jsx"), /!canManage && user\.password_known \? \([\s\S]{0,200}t\("passwordWithheld"\)/);
  assert.match(read("components/system-users/system-users-table.jsx"), /!\(row\.original\.password_known \?\? row\.original\.password\)/);
  assert.match(read("components/system-users/system-users-cards.jsx"), /!\(user\.password_known \?\? user\.password\)/);
  assert.match(read("components/applications/deployment/webhook-card.jsx"), /!canManage \? \([\s\S]{0,200}t\("webhook\.secretWithheld"\)/);
  for (const l of locales) {
    const m = JSON.parse(read(`messages/${l}.json`));
    assert.ok(m.databases.credentials.passwordWithheld && m.databases.users.passwordWithheld && m.applications.deployment.webhook.secretWithheld, l);
  }
});

test("Firewall and Fail2ban use the address the BROWSER connects from, never the one rendered with the page", () => {
  const ip = read("components/network/browser-ip.jsx");
  assert.match(ip, /firewall: \{ load: getFirewall, pick: \(data\) => data\?\.your_ip \}/);
  assert.match(ip, /fail2ban: \{ load: getFail2ban, pick: \(data\) => data\?\.fail2ban\?\.your_ip \}/);
  assert.doesNotMatch(read("app/(app)/firewall/page.jsx"), /your_ip/);
  assert.doesNotMatch(read("app/(app)/fail2ban/page.jsx"), /your_ip/);
  assert.match(read("components/firewall/rules-card.jsx"), /const yourIp = useBrowserIp\(\);/);
  for (const f of ["fail2ban-tabs.jsx", "protection-section.jsx", "ignore-list-card.jsx"]) {
    assert.match(read(`components/fail2ban/${f}`), /const yourIp = useBrowserIp\(\);/, f);
  }
});
