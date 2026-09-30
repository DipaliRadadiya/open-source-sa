import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { isBackupStale } from "../lib/backups/stale.js";
import { phpSettingsFormSchema } from "../lib/schemas/php-settings.js";
import { backupTargetFormSchema } from "../lib/schemas/backup.js";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const messages = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]));
const at = (obj, path) => path.split(".").reduce((node, key) => node?.[key], obj);

// "DD-MM-YYYY HH:mm:ss", UTC wall clock, as BackupResource formats it.
const stamp = (ms) => {
  const d = new Date(ms);
  const p = (n) => String(n).padStart(2, "0");
  return `${p(d.getUTCDate())}-${p(d.getUTCMonth() + 1)}-${d.getUTCFullYear()} ${p(d.getUTCHours())}:${p(d.getUTCMinutes())}:${p(d.getUTCSeconds())}`;
};

test("BK-B: Clear is offered only once the server would accept it", () => {
  const now = Date.UTC(2026, 8, 25, 12, 0, 0);
  const min = 60 * 1000;
  // A run one second old — where Clear used to appear.
  assert.equal(isBackupStale({ status: "running", started_at: stamp(now - 1000) }, now), false);
  // No heartbeat: stale after 65 minutes from the start.
  assert.equal(isBackupStale({ status: "pending", created_at: stamp(now - 64 * min) }, now), false);
  assert.equal(isBackupStale({ status: "pending", created_at: stamp(now - 66 * min) }, now), true);
  // A heartbeat keeps it alive whatever its age; stale 25 minutes after the last one.
  assert.equal(isBackupStale({ status: "running", started_at: stamp(now - 300 * min), progress_at: stamp(now - 10 * min) }, now), false);
  assert.equal(isBackupStale({ status: "running", started_at: stamp(now - 300 * min), progress_at: stamp(now - 26 * min) }, now), true);
  // Finished runs are never stale.
  assert.equal(isBackupStale({ status: "verified", created_at: stamp(now - 999 * min) }, now), false);
  assert.match(read("components/backups/backups-history-table.jsx"), /canClear && isBackupStale\(backup\)/);
  assert.match(read("components/backups/backups-cards.jsx"), /canClear && isBackupStale\(backup\)/);
});

test("BK-A: an empty history is not reported as backed up or as a recent backup", () => {
  const panel = read("components/applications/backups/backups-panel.jsx");
  // Since RP-2 a run still in progress does not count as kept either.
  assert.match(panel, /noneKept=\{!backupsFailed && total - backups\.filter\(\(b\) => BACKUP_IN_FLIGHT\.includes\(b\.status\)\)\.length === 0\}/);
  assert.match(panel, /if \(noneKept\) return "empty";/);
  for (const l of LOCALES) {
    assert.ok(at(messages[l], "backups.application.state.empty.title"), l);
    assert.ok(at(messages[l], "backups.application.noneKept"), l);
  }
});

test("BK-C: the safety copy is labelled on the site's own page", () => {
  assert.match(read("components/backups/backups-history-table.jsx"), /backup\.is_safety && !table\.options\.meta\?\.showSite \? <SafetyBadge \/>/);
});

test("BK-E: a running restore blocks Restore, Back up now, Retry and Turn off", () => {
  const panel = read("components/applications/backups/backups-panel.jsx");
  assert.match(panel, /restoreInFlight=\{restoreRunning\}/);
  assert.match(panel, /onStatusChange=\{setRestoreStatus\}/);
  assert.match(read("components/backups/restore-progress.jsx"), /statusRef\.current\?\.\(next\.status, next\.id\)/);
});

test("BK-H/I: the setup dialog names the site and hands 'Back up now' back to the page", () => {
  const dialog = read("components/backups/setup-backups-dialog.jsx");
  assert.match(dialog, /name: application\?\.name \?\? applicationName \?\? ""/);
  assert.match(dialog, /onStarted\?\.\(\);/);
  assert.match(read("components/applications/backups/backups-panel.jsx"), /setQueuedAfter\(newestId\);\n\s+\}\}/);
});

test("BK-K: a refused history says it is a permission, and hides All backups", () => {
  assert.match(read("app/(app)/applications/[application]/backups/page.jsx"), /backupsForbidden=\{backupsFailed && backupsStatus === 403\}/);
  for (const l of LOCALES) assert.ok(at(messages[l], "backups.application.historyForbidden"), l);
});

test("BK-O: exclude lists report the bad line, and count lines not characters", () => {
  const schema = backupTargetFormSchema({ frequencies: [{ value: "manual", label: "Manual", time: null }], default_frequency: "manual", types: [{ value: "full", label: "Full" }], retention: { min: 1, max: 365 } });
  const tooMany = schema.safeParse({ application_id: 1, storage_destination_id: 1, type: "full", enabled: false, frequency: "manual", retention_count: 7, file_excludes: Array.from({ length: 101 }, (_, i) => `d${i}`), database_excludes: [] });
  assert.equal(tooMany.success, false);
  assert.ok(tooMany.error.issues.some((issue) => issue.message === "maxLines100"));
  assert.match(read("components/backups/backup-settings-fields.jsx"), /<FormMessage>\{lineError\(fieldState\.error\)\}<\/FormMessage>/);
  for (const l of LOCALES) {
    assert.ok(messages[l].validation.maxLines100, l);
    assert.match(at(messages[l], "backups.form.excludeLineError"), /\{line\}.*\{message\}/, l);
  }
});

test("BK-Q: undo restores the same scope the restore replaced", () => {
  assert.match(read("components/backups/restore-progress.jsx"), /preferred_type: restore\.type/);
  assert.match(read("components/backups/restore-dialog.jsx"), /allowed\.includes\(backup\?\.preferred_type\)/);
});

test("PHP-A: range errors name the limits; nothing emits the bare 'range' code", () => {
  const base = { php_version: "8.4", memory_limit: "256M", upload_max_filesize: "64M", post_max_size: "64M", max_execution_time: 30, max_input_time: 60, max_input_vars: 1000, session_gc_maxlifetime: 1440, pm_type: "ondemand", pm_max_children: 5, pm_max_requests: 500 };
  const cases = [["max_execution_time", 5000, "rangeSeconds3600"], ["max_input_time", -2, "rangeInputTime"], ["max_input_vars", 50, "rangeInputVars"], ["session_gc_maxlifetime", 10, "rangeSession"], ["pm_max_children", 101, "rangeWorkers"], ["pm_max_requests", -1, "rangeMaxRequests"], ["auto_prepend_file", "../x.php", "pathNoTraversal"]];
  for (const [field, value, code] of cases) {
    const result = phpSettingsFormSchema.safeParse({ ...base, [field]: value });
    assert.equal(result.error?.issues[0]?.message, code, field);
    for (const l of LOCALES) assert.ok(messages[l].validation[code], `${l} ${code}`);
  }
  assert.doesNotMatch(read("lib/schemas/php-settings.js"), /"range"\)/);
  // The custom box reads "requiredField" from `common`, as FormMessage does.
  assert.match(read("components/applications/php/php-panel.jsx"), /raw === "requiredField"\s*\n\s*\? tCommon\("requiredField"\)/);
});

test("PHP-B: a save sends only what changed (plus the version)", () => {
  assert.match(read("components/applications/php/php-panel.jsx"), /key === "php_version" \|\| dirtyFields\[key\]/);
});

test("PHP-C/D/F/H: function names checked, refusal shown by Save, read-only locked, error tabs marked", () => {
  const panel = read("components/applications/php/php-panel.jsx");
  assert.match(panel, /new Set\(draft\.split/);
  assert.match(panel, /setRefused\(typed\.filter/);
  assert.doesNotMatch(panel, /setNeedsPool/);
  assert.match(panel, /toast\.error\(refused\.settings\[0\]\)/);
  assert.match(panel, /const locked = !canManage \|\| saving;/);
  assert.doesNotMatch(panel.slice(panel.indexOf("function DedicatedPhpPanel("), panel.indexOf("// ─── Reusable primitives")), /disabled=\{saving\}/);
  assert.match(panel, /form\.handleSubmit\(save, \(invalid\) => showErrors\(invalid\)\)/);
  for (const l of LOCALES) {
    assert.ok(at(messages[l], "applications.php.fixErrors"), l);
    assert.match(at(messages[l], "applications.php.blocked.invalidName"), /\{names\}/, l);
  }
});

test("PHP-E: the extra-directives example is one the pool accepts", () => {
  for (const l of LOCALES) {
    assert.match(at(messages[l], "applications.php.fields.directivesPlaceholder"), /^php_admin_value\[/, l);
    assert.match(at(messages[l], "applications.php.hints.directives"), /php_admin_value\[/, l);
  }
});

test("PHP-G: the over-commit sentence compares the total", () => {
  assert.match(read("components/applications/php/php-panel.jsx"), /required: formatBytes\(budget\.committed\)/);
});
