import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { operationHref } from "../lib/operations/href.js";
import { operationsResponseSchema, isActive } from "../lib/schemas/operation.js";
import { phpCommonSettingsFormSchema } from "../lib/schemas/php.js";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");
const app = { type: "application", id: 3, name: "qa-memos" };

// Krishna, 21 Oct: background jobs in the top bar (RC-20).
test("each job links to its own screen, and an application screen needs Applications access", () => {
  const can = { canOpenApplications: true };
  assert.equal(operationHref({ kind: "backup", subject: app }, can), "/applications/3/backups");
  assert.equal(operationHref({ kind: "restore", subject: { type: "database", id: 7 } }, can), "/backups");
  assert.equal(operationHref({ kind: "deployment", subject: app }, can), "/applications/3/deployment");
  assert.equal(operationHref({ kind: "database_import", subject: { type: "database", id: 7 } }, can), "/databases/7");
  assert.equal(operationHref({ kind: "runtime_install", subject: { type: "server", id: null, name: "Node.js 22" } }, can), "/node");
  assert.equal(operationHref({ kind: "runtime_install", subject: { type: "server", id: null, name: "PHP 8.3" } }, can), "/php");
  assert.equal(operationHref({ kind: "panel_update", subject: null }, can), "/admin/panel-update");
  // Without Applications access the row shows, unlinked, rather than linking to a refusal.
  assert.equal(operationHref({ kind: "backup", subject: app }, { canOpenApplications: false }), null);
  assert.equal(operationHref({ kind: "something_new", subject: app }, can), null);
});

test("a job kind added later still parses, and only waiting/running count as active", () => {
  const parsed = operationsResponseSchema.parse({ operations: [{ kind: "future_kind", id: 1, state: "running" }], meta: { running: 1, hours: null } });
  assert.equal(parsed.operations[0].kind, "future_kind");
  assert.equal(isActive({ state: "waiting" }), true);
  assert.equal(isActive({ state: "done" }), false);
  assert.equal(isActive({ state: "failed" }), false);
});

test("the badge is only offered to someone who can see at least one kind of job", () => {
  const layout = read("app/(app)/layout.jsx");
  assert.match(layout, /user\?\.is_admin \|\| OPERATION_PERMISSIONS\.some\(\(name\) => can\(permissions, name, "view"\)\)/);
  assert.match(read("components/sections/admin-header.jsx"), /<OperationsIndicator canOpenApplications \/>/);
});

test("a finished job is announced once, from the change in the running list", () => {
  const indicator = read("components/sections/operations-indicator.jsx");
  assert.match(indicator, /The first answer is the starting point, not news\./);
  assert.match(indicator, /if \(document\.hidden\) return;/);
  // On the job's own page that page announces it; no second toast.
  assert.match(indicator, /if \(href && window\.location\.pathname === href\) return;/);
  // RC-36: a job that starts and ends between two polls comes back in the active poll, and
  // each finished job is said once even when the clock margin repeats it.
  assert.match(indicator, /const since = lastPoll\.current;/);
  assert.match(indicator, /if \(announced\.current\.has\(key\)\) return;/);
  assert.match(indicator, /finished\.forEach\(\(row\) => announced\.current\.add\(key\(row\)\)\);/);
  assert.match(read("lib/api/operations.js"), /finished_after: finishedAfter/);
});

// The sidebar showed PHP, Staging and Deployments for a Docker app while it loaded.
test("the application menu waits for the application's own menu", () => {
  const sidebar = read("components/sections/app-sidebar.jsx");
  assert.match(sidebar, /const pendingApplication = Boolean\(applicationId\) && !resolved;/);
  assert.match(sidebar, /\{pendingApplication \? null : groups\.map\(/);
  // Fixed widths: random ones break hydration.
  assert.doesNotMatch(sidebar, /SidebarMenuSkeleton/);
});

// RC-8: the common php.ini settings as fields.
test("PHP settings: empty means unchanged, and values follow the API's rules", () => {
  const ok = phpCommonSettingsFormSchema.safeParse({ memory_limit: "512M", upload_max_filesize: "", max_execution_time: "120", max_input_time: "-1", max_input_vars: "" });
  assert.equal(ok.success, true);
  assert.equal(phpCommonSettingsFormSchema.safeParse({ memory_limit: "512MB", upload_max_filesize: "", max_execution_time: "", max_input_time: "", max_input_vars: "" }).success, false);
  assert.equal(phpCommonSettingsFormSchema.safeParse({ memory_limit: "", upload_max_filesize: "", max_execution_time: "9999", max_input_time: "", max_input_vars: "" }).success, false);
  assert.equal(phpCommonSettingsFormSchema.safeParse({ memory_limit: "", upload_max_filesize: "", max_execution_time: "", max_input_time: "", max_input_vars: "50" }).success, false);
});

test("PHP settings: the upload field raises post_max_size so uploads that size get through", () => {
  const form = read("components/php/php-common-settings.jsx");
  assert.match(form, /payload\.post_max_size = payload\.upload_max_filesize;/);
  // The two tabs tell each other, so neither saves over the other's change.
  assert.match(form, /source: "settings"/);
  assert.match(read("components/php/ini-editor.jsx"), /if \(dirty\) setStale\(true\);/);
});

test("firewall rules added outside the panel are listed with Manage and a red Remove", () => {
  const card = read("components/firewall/unmanaged-rules-card.jsx");
  assert.match(card, /adoptUnmanagedRule\(rule\.key\)/);
  assert.match(card, /variant="destructive"/);
  assert.match(card, /!rule\.adoptable \? \(rule\.reason \|\| t\("notAdoptable"\)\)/);
  // The API refuses to remove one it cannot hold (422), so Remove is disabled with why.
  assert.match(card, /!rule\.adoptable \? t\("removeOnServer"\)/);
  assert.match(read("app/(app)/firewall/page.jsx"), /<UnmanagedRulesCard rules=\{data\.unmanaged_rules\}/);
});

test("the Node page points to Setup when the build tools are missing", () => {
  const page = read("app/(app)/node/page.jsx");
  assert.match(page, /!buildTools\.data\.build_tools\.installed/);
  assert.match(page, /href="\/setup"/);
});

// RC-16 made push and update-from-live answer 202 with a queued run; the dialogs said
// "pushed" on that, and a failure later was never shown (Krishna, 21 Oct).
test("staging push and pull say they started, and the page shows how the run ends", async () => {
  for (const file of ["push-staging-dialog.jsx", "pull-staging-dialog.jsx"]) {
    const dialog = read(`components/applications/staging/${file}`);
    assert.match(dialog, /toast\.info\(t\("started"/, file);
    assert.doesNotMatch(dialog, /toast\.success\(t\("done"/, file);
  }
  const status = read("components/applications/staging/staging-run-status.jsx");
  assert.match(status, /<AutoRefresh intervalMs=\{3000\}/);
  assert.match(status, /runErrorText\(run\.error\)/);
  const panel = read("components/applications/staging/staging-panel.jsx");
  assert.match(panel, /const busyReason = stagingRunActive\(latestRun\) \? t\("run\.busy"\) : null;/);
  const { applicationStagingResponseSchema, stagingRunActive } = await import("../lib/schemas/application-staging.js");
  const parsed = applicationStagingResponseSchema.parse({
    staging: null,
    latest_run: { id: 7, kind: "push", mode: "files", backup: false, status: "failed", error: { message: "Backup failed", code: "staging_failed" }, error_status: 409, backup_id: null },
  });
  assert.equal(parsed.latest_run.error.message, "Backup failed");
  assert.equal(stagingRunActive({ status: "queued" }), true);
  assert.equal(stagingRunActive({ status: "succeeded" }), false);
});

// Databases outside any application (RC-23) had schedules but no place on Backups →
// Overview, so an unprotected one went unnoticed.
test("Backups overview lists standalone databases and links each to its Backups tab", async () => {
  const { databaseBackupTargetsResponseSchema } = await import("../lib/schemas/backup.js");
  const parsed = databaseBackupTargetsResponseSchema.parse({
    backup_targets: [
      { database_id: 4, database_name: "shop", database_engine: "mariadb", backup_target: null, last_backup: null },
    ],
    meta: { total: 1 },
  });
  assert.equal(parsed.backup_targets[0].backup_target, null);
  const card = read("components/backups/database-coverage-card.jsx");
  assert.match(card, /href=\{`\/databases\/\$\{row\.original\.database\.id\}\?tab=backups`\}/);
  assert.match(card, /t\("notSetUpLine"\)/);
  assert.match(card, /canOpenDatabases \?/);
  const page = read("app/(app)/backups/page.jsx");
  assert.match(page, /getDatabaseBackupCoverage\(\)/);
  assert.match(page, /canOpenDatabases=\{can\(permissions, "database", "view"\)\}/);
});

// The API resets permissions only for php/static serving profiles since 22 Oct (cbb078e5);
// the button was still offered on Node and Docker apps and failed after the confirm.
test("Fix permissions is disabled with a reason on Node and Docker applications", () => {
  const button = read("components/applications/files/fix-permissions-button.jsx");
  assert.match(button, /const RESETTABLE_PROFILES = \["php", "static"\];/);
  assert.match(button, /t\("fixPermissions\.notForRuntime"\)/);
  assert.match(button, /disabled=\{Boolean\(reason\)\}/);
  assert.match(read("app/(app)/applications/[application]/files/page.jsx"), /servingProfile=\{application\.serving_profile\}/);
});

// RC-19: a restore names its database. The Restores list showed a database's own restore
// as "Deleted application", and its Backups tab lost the restore after a reload.
test("restores of a database on its own are named, filterable, and followed after a reload", async () => {
  const { restoresResponseSchema } = await import("../lib/schemas/backup.js");
  const parsed = restoresResponseSchema.parse({
    restores: [{ id: 5, application_id: null, database_id: 6, database_name: "qa_small", database_engine: "mariadb", type: "database", status: "succeeded" }],
  });
  const { backupSubject } = await import("../lib/backups/backup-subject.js");
  assert.deepEqual(backupSubject(parsed.restores[0]), { kind: "database", name: "qa_small", href: "/databases/6?tab=backups", archived: false });
  const { filterDatabases } = await import("../lib/backups/filter-databases.js");
  assert.deepEqual(filterDatabases([{ database: { id: 6, name: "qa_small" } }], { id: "9", name: "gone" }), [{ id: 6, name: "qa_small" }, { id: "9", name: "gone" }]);
  assert.match(read("lib/backups/get-backups.js"), /"filter\[database_id\]": searchParams\.database,\n\s+"filter\[status\]": oneOf\(searchParams\.status, RESTORE_STATUSES\)/);
  assert.match(read("components/backups/restores-list.jsx"), /const subject = backupSubject\(row\.original\);/);
  assert.match(read("components/backups/subject-filter.jsx"), /database: kind === "database" \? id : undefined/);
  assert.match(read("app/(app)/databases/[database]/page.jsx"), /getActiveRestore\(undefined, \{\n\s+database: data\.id,/);
});

// The list names the attached site since 22 Oct (583aab36). It was looked up in the
// manager-only applications list, so a view-only reader saw "Another application".
test("the database list names the attached application from the list itself", async () => {
  const { attachedApplication } = await import("../lib/backups/database-availability.js");
  assert.equal(attachedApplication({ application_id: null }, []), null);
  assert.deepEqual(attachedApplication({ application_id: 19, application_name: "akaunting" }, []), { id: 19, name: "akaunting" });
  assert.deepEqual(attachedApplication({ application_id: 19, application_name: null }, [{ id: 19, name: "renamed" }]), { id: 19, name: "renamed" });
  assert.deepEqual(attachedApplication({ application_id: 7 }, []), { id: 7, name: null });
  const { databaseSchema } = await import("../lib/schemas/database.js");
  assert.equal(databaseSchema.parse({ id: 1, name: "x", engine: "mariadb", application_id: 19, application_name: "akaunting" }).application_name, "akaunting");
  assert.match(read("app/(app)/databases/page.jsx"), /canOpenApplications=\{can\(permissions, "application", "view"\)\}/);
});
