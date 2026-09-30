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

test("Code review C–G: role editing refuses to open on a failed permission catalog", () => {
  assert.match(read("lib/permissions/get-permission-catalog.js"), /if \(!permissions\.length\) return empty\(res\.status, "shape"\);/);
  for (const p of ["app/admin/roles/[role]/page.jsx", "app/admin/roles/new/page.jsx"]) assert.match(read(p), /if \(catalog\.failed\) return <LoadFailed/, p);
});

test("Code review C–G: an admin cannot untick their own admin access", () => {
  assert.match(read("components/admin/users/user-form-dialog.jsx"), /disabled=\{isEdit && isSelf\}/);
  assert.match(read("components/admin/users/user-row-actions.jsx"), /isSelf=\{isSelf\}/);
});

test("Code review C–G: fail2ban lockout guards keep working with the browser-sourced IP", () => {
  assert.match(read("components/fail2ban/recommended-setup.jsx"), /acknowledged: Boolean\(yourIp\)/);
  assert.match(read("components/fail2ban/jails-card.jsx"), /const ignoreIp = typedIp \?\? yourIp \?\? "";/);
  assert.match(read("components/fail2ban/ignore-list-card.jsx"), /const live = edited && sameList\(edited\.base, saved\) \? edited\.ips : null;/);
  const ban = read("components/fail2ban/ban-ip-dialog.jsx");
  assert.match(ban, /const activeJails = jails\.filter\(\(j\) => j\.enabled\);/);
  assert.match(ban, /disabled=\{pending \|\| !ip\.trim\(\) \|\| !jail \|\| isSelf\}/);
});

test("Code review C–G: firewall protected rules lock always; unreadable state is its own state", () => {
  assert.match(read("components/firewall/add-rule-dialog.jsx"), /const ruleLocked = editing && Boolean\(rule\.protected\);/);
  assert.match(read("lib/schemas/firewall.js"), /enabled: z\.boolean\(\)\.nullable\(\),/);
  assert.match(read("lib/firewall/state.js"), /if \(enabled === null \|\| enabled === undefined\) return "unknown";/);
  for (const l of locales) assert.ok(JSON.parse(read(`messages/${l}.json`)).firewall.status.unknownTitle, l);
});

test("Code review C–G: databases — remote access restart confirm, nested user errors, export download gated", () => {
  for (const f of ["add-user-dialog.jsx", "edit-user-dialog.jsx", "create-database-dialog.jsx"]) {
    assert.match(read(`components/databases/${f}`), /restart\.ask\(error\)[\s\S]{0,160}restart_cluster: true/, f);
  }
  assert.match(read("components/databases/create-database-dialog.jsx"), /if \(field && form\.getValues\(field\) !== undefined\) form\.setError\(field/);
  assert.match(read("components/databases/database-exports.jsx"), /canManage && row\.download_url && row\.available/);
});

test("Code review C–G: settings survive an unexpected PermitRootLogin; PHP removal failure is not an install failure", () => {
  assert.match(read("lib/schemas/settings.js"), /permit_root_login: z\s*\.string\(\)/);
  assert.match(read("components/runtime/version-status.jsx"), /if \(removeFailed\(version\)\) return null;/);
  assert.match(read("app/(app)/php/page.jsx"), /const installState = versionState\(current\);/);
});

test("Flicker sweep: two waiters on one hook both resolve", () => {
  const src = read("hooks/use-refresh.js");
  assert.match(src, /const after = useRef\(\[\]\);/);
  assert.match(src, /waiting\.forEach\(\(run\) => run\(\)\);/);
  assert.doesNotMatch(src, /after\.current = (fn|resolve);/);
});

test("Flicker sweep: dialogs re-read the page before they close and toast", () => {
  assert.match(read("hooks/use-action.js"), /if \(refresh\) await refreshAndWait\(\);\s*if \(success\) toast\.success/);
  const swept = {
    "components/firewall/rules-card.jsx": "deleteFirewallRule",
    "components/firewall/quick-add-card.jsx": "deleteFirewallRule",
    "components/firewall/firewall-status-card.jsx": "toggleFirewall",
    "components/firewall/add-rule-dialog.jsx": "createFirewallRule",
    "components/fail2ban/ban-ip-dialog.jsx": "banIp",
    "components/fail2ban/banned-card.jsx": "unbanIp",
    "components/services/service-actions.jsx": "runServiceAction",
    "components/admin/users/reset-password-dialog.jsx": "resetUserPassword",
    "components/admin/users/user-form-dialog.jsx": "createUser",
    "components/admin/roles/sync-permissions-button.jsx": "syncPermissions",
    "components/admin/central/central-panel.jsx": "disableCentral",
    "components/php/version-summary.jsx": "removePhpVersion",
    "components/node/version-summary.jsx": "removeNodeVersion",
    "components/php/ioncube-card.jsx": "removeIonCube",
    "components/php/extensions-card.jsx": "setPhpExtension",
    "components/integrations/git/disconnect-dialog.jsx": "disconnectAccount",
    "components/integrations/git/replace-token-dialog.jsx": "updateAccount",
    "components/integrations/git/edit-dialog.jsx": "updateAccount",
    "components/applications/relink-git-account-dialog.jsx": "relinkGitAccount",
    "components/applications/web-root-dialog.jsx": "updateWebRoot",
    "components/applications/attach-database-dialog.jsx": "attachDatabase",
    "components/applications/staging/push-staging-dialog.jsx": "pushApplicationStaging",
    "components/applications/files/file-editor-dialog.jsx": "saveFileContent",
    "components/databases/database-exports.jsx": "deleteExport",
  };
  for (const [file, call] of Object.entries(swept)) {
    const src = read(file);
    const at = src.indexOf(`await ${call}(`);
    assert.ok(at > 0, `${file}: ${call}`);
    const after = src.slice(at, src.indexOf("} catch", at));
    assert.match(after, /await refreshAndWait\(\);[\s\S]*toast\.success|await refreshAndWait\(\);[\s\S]*showActionSuccess/, file);
    assert.doesNotMatch(after, /router\.refresh\(\);/, file);
  }
});

test("Deleting a Block rule does not say it will block things", async () => {
  const { deleteRuleBodyKey } = await import("../lib/firewall/state.js");
  assert.equal(deleteRuleBodyKey(true, { action: "allow" }), "rules.confirmBodyOn");
  assert.equal(deleteRuleBodyKey(true, { action: "deny" }), "rules.confirmBodyOnDeny");
  assert.equal(deleteRuleBodyKey(true, { action: "allow", enabled: false }), "rules.confirmBodyRuleOff");
  assert.equal(deleteRuleBodyKey(false, { action: "deny" }), "rules.confirmBodyOff");
  for (const l of locales) {
    const r = JSON.parse(read(`messages/${l}.json`)).firewall.rules;
    assert.ok(r.confirmBodyOnDeny.includes("{rule}") && r.confirmBodyRuleOff.includes("{rule}"), l);
  }
});

test("A risky Quick add tile asks before opening a database to everyone", () => {
  const src = read("components/firewall/quick-add-card.jsx");
  assert.match(src, /risky && !off\s*\?\s*setOpening\(\{ preset, name: risky \}\)/);
  assert.match(src, /onConfirm=\{\(\) => add\(\[opening\.preset\], opening\.preset\.key\)\}/);
  assert.match(src, /\} finally \{[\s\S]{0,200}await refreshAndWait\(\);[\s\S]*?toast\.success\(t\("quick\.added"/);
  for (const l of locales) {
    const q = JSON.parse(read(`messages/${l}.json`)).firewall.quick;
    assert.ok(q.riskyTitle.includes("{name}") && q.riskyBody.includes("{port}") && q.riskyConfirm, l);
  }
});

test("Activity Log shows everyone's server activity, with who did it", () => {
  const page = read("app/(app)/activity-log/page.jsx");
  assert.match(page, /getServerActivity\(sp\)/);
  assert.match(page, /<MyActivityTable[\s\S]*?showUser/);
  assert.match(read("lib/activity-log/get-server-activity.js"), /read\("\/server\/activity-log"/);
  assert.match(read("components/activity-log/my-activity-table.jsx"), /showUser \? \[\{ id: "user"/);
  const history = read("components/firewall/history-dialog.jsx");
  assert.match(history, /everyone \? getServerActivityByType : getMyActivityByType/);
  assert.match(read("app/(app)/firewall/page.jsx"), /historyForEveryone = can\(permissions, "activity_log", "view"\)/);
  for (const l of locales) {
    const m = JSON.parse(read(`messages/${l}.json`));
    assert.ok(m.activity.server.subtitle && m.firewall.history.openLog, l);
    assert.equal(m.activity.mine.subtitle, undefined, l);
  }
});

test("Server log filters never offer account verbs", async () => {
  const { actionsForScope, typesForScope } = await import("../lib/activity-log/labels.js");
  const own = { types: ["user", "central"], actions: { all: ["logged_in", "enabled"], user: ["logged_in"], central: ["enabled"] } };
  assert.deepEqual(typesForScope(own.types, "server"), []);
  assert.deepEqual(actionsForScope(own.actions, own.types, "server").all, []);
  assert.deepEqual(actionsForScope({ all: ["created"] }, [], "server").all, ["created"]);
});

test("Backups: the setup dialog keeps its saved step and the overview watches the new run", async () => {
  const dialog = read("components/backups/setup-backups-dialog.jsx");
  const submit = dialog.slice(dialog.indexOf("async function onSubmit"), dialog.indexOf("async function backUpNow"));
  assert.doesNotMatch(submit, /router\.refresh\(\)|refreshAndWait\(\)/);
  assert.match(dialog, /await runBackupNow\(saved\.id\);\s*markBackupStarted\(\);\s*onStarted\?\.\(\);\s*await refreshAndWait\(\);/);
  assert.match(dialog, /async function finish\(\)/);
  const card = read("components/backups/coverage-card.jsx");
  assert.match(card, /useState\(\(\) => backupStartedWithin\(JUST_STARTED_MS\)\)/);
  assert.match(card, /onStarted=\{\(\) => setJustStarted\(true\)\}/);
  globalThis.sessionStorage = new Map();
  globalThis.sessionStorage.setItem = globalThis.sessionStorage.set; globalThis.sessionStorage.getItem = globalThis.sessionStorage.get;
  globalThis.window = {};
  const { markBackupStarted, backupStartedWithin } = await import("../lib/backups/just-started.js");
  assert.equal(backupStartedWithin(90_000), false);
  markBackupStarted();
  assert.equal(backupStartedWithin(90_000), true);
  delete globalThis.window; delete globalThis.sessionStorage;
});

test("Backups: Check again on a stalled restore asks about that restore and resumes watching", () => {
  const src = read("components/backups/restore-progress.jsx");
  assert.match(src, /async function checkAgain\(\) \{[\s\S]*?await fetchRestore\(id\)[\s\S]*?setStalled\(false\);\s*setRound/);
  assert.match(src, /\[inFlight, id, queued, router, round\]/);
  assert.match(src, /onClick=\{checkAgain\}/);
  for (const l of locales) assert.ok(JSON.parse(read(`messages/${l}.json`)).backups.progress.checkFailed, l);
});

test("Database user and host rules match the API, so refusals are translated", async () => {
  const { databaseUsernameProblem, hostProblem, databaseUserFormSchema, createDatabaseSchema, passwordFormSchema } = await import("../lib/schemas/database.js");
  assert.equal(databaseUsernameProblem("u".repeat(32)), null);
  assert.equal(databaseUsernameProblem("u".repeat(33)), "max32");
  assert.equal(databaseUsernameProblem("root"), "databaseUsernameReserved");
  assert.equal(databaseUsernameProblem("a-b"), "databaseUsername");
  assert.equal(hostProblem("203.0.113.0/24"), null);
  assert.equal(hostProblem("example.com"), "databaseHost");
  assert.equal(hostProblem("2001:db8::1"), "databaseHost");
  assert.equal(hostProblem(""), "required_host");
  const add = databaseUserFormSchema.safeParse({ username: "ok_user", password: "short", connection_preference: "localhost" });
  assert.equal(add.success, false);
  assert.equal(databaseUserFormSchema.safeParse({ username: "ok_user", password: "", connection_preference: "localhost" }).success, true);
  const create = createDatabaseSchema(new Set()).safeParse({ name: "db1", engine: "mariadb", create_user: true, username: "root", connection_preference: "remote", host: "example.com" });
  assert.deepEqual(create.error.issues.map((i) => i.message).sort(), ["databaseHost", "databaseUsernameReserved"]);
  assert.equal(passwordFormSchema.safeParse({ password: "x".repeat(256) }).success, false);
  for (const l of locales) {
    const v = JSON.parse(read(`messages/${l}.json`)).validation;
    assert.ok(v.databaseUsernameReserved && v.databaseHost && v.max32 && v.max255, l);
  }
});

test("Databases QA: honest states and quieter dialogs", () => {
  const bar = read("components/databases/engine-bar.jsx");
  assert.match(bar, /const stopped = list\.filter\(/);
  assert.match(bar, /stopped\.map\(\(engine\) =>[\s\S]*?t\("engineList\.unreachable"\)[\s\S]*?href="\/services"/);
  const page = read("app/(app)/databases/[database]/page.jsx");
  assert.match(page, /const engineDown = Boolean\(engineRow\?\.installed\) && !engineRow\.running;/);
  assert.match(page, /tables: tables\.failed \|\| engineDown \? null/);
  assert.match(read("lib/databases/get-monitor.js"), /const result = await read\(`\/databases\/\$\{databaseId\}\/tables`/);
  assert.match(read("components/databases/database-tabs.jsx"), /section\.count === null \? null/);
  assert.match(read("components/databases/health-summary.jsx"), /if \(!status\) \{[\s\S]*?t\("unknownTitle"\)/);
  assert.match(read("app/(app)/databases/monitor/page.jsx"), /\{tEngines\(engine\.engine\)\}/);
  assert.match(read("components/databases/process-list.jsx"), /onDone: async \(\) => \{[\s\S]*?await refreshAndWait\(\);[\s\S]*?toast\.success\(t\("killed"\)\)/);
  for (const f of ["create-database-dialog.jsx", "add-user-dialog.jsx"]) assert.match(read(`components/databases/${f}`), /await refreshAndWait\(\);\s*toast\.success/, f);
  assert.match(read("components/databases/add-user-dialog.jsx"), /<CreatedCredentials\s+forUser/);
  assert.match(read("components/databases/databases-table.jsx"), /aria-label=\{`\$\{t\("columns\.notLinked"\)\}\. /);
  for (const l of locales) {
    const d = JSON.parse(read(`messages/${l}.json`)).databases;
    assert.ok(d.detail.engineDown.includes("{engine}") && d.tables.unavailable.includes("{engine}") && d.monitor.health.unknownBody.includes("{engine}") && d.created.userTitle.includes("{username}") && d.monitor.loadFailed, l);
  }
});

test("Manual-only backups are labelled Manual only, not Paused (Krishna, 29 Sep)", () => {
  for (const l of locales) {
    const m = JSON.parse(read(`messages/${l}.json`));
    const short = m.backups.form.automaticOffShort;
    assert.equal(m.backups.coverage.status.paused, short, l);
    assert.equal(m.applications.backups.state.paused, short, l);
  }
  assert.equal(JSON.parse(read("messages/en.json")).backups.application.state.paused.title, "Manual backups only");
});

test("Backups overview: the table only shows when the widest locale fits, and its spanning line wraps", () => {
  const card = read("components/backups/coverage-card.jsx");
  assert.match(card, /<div className="@container">\s*<div className="@min-\[1180px\]:hidden">\s*<CoverageCards/);
  assert.match(card, /<div className="hidden @min-\[1180px\]:block">\s*<CoverageTable/);
  const table = read("components/backups/coverage-table.jsx");
  assert.match(table, /whitespace-normal text-muted-foreground\/80">\{t\("notSetUpLine"\)\}/);
  assert.match(table, /header: wrapping\(t\("columns\.type"\)\)/);
});

test("Firewall form accepts port 65535 like the API (PORT_MAX since 03aca0da)", () => {
  const src = read("lib/schemas/firewall.js");
  assert.match(src, /parsed\.from > 65535 \|\| \(parsed\.to && parsed\.to > 65535\)/);
  for (const l of locales) assert.doesNotMatch(read(`messages/${l}.json`), /65534/, l);
});

test("Fail2ban ban rules refuse out-of-range numbers on the field, not with the API's English 422", () => {
  const src = read("components/fail2ban/ban-rules-card.jsx");
  assert.match(src, /const maxretryError = inRange\(maxretry, 2, 100\)/);
  assert.match(src, /const findtimeError = inRange\(findtime, 30, 86400\)/);
  assert.match(src, /: maxretryError \|\| findtimeError;/);
  for (const l of locales) { const s = JSON.parse(read(`messages/${l}.json`)).fail2ban.settings; assert.ok(s.maxretryRange && s.findtimeRange, l); }
});
