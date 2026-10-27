import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

import { hasRunHistory, runDuration } from "../lib/cron-jobs/run-duration.js";
import { cronjobRunsResponseSchema, cronjobSchema } from "../lib/schemas/cronjob.js";

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\{\/\*[\s\S]*?\*\/\}/g, "").replace(/\/\/.*$/gm, "");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const job = { id: 1, name: "Backup", username: "qa", command: "php artisan backup", expression: "0 2 * * *", active: true };

// fb0819c9 + e106f002: last_run/running on the list, GET …/runs, POST …/run.
test("the list schema keeps last_run and running, and an older API without them", () => {
  const row = cronjobSchema.parse({
    ...job,
    running: false,
    last_run: { status: "failed", exit_code: 127, manual: true, overlapped: false, started_at: null, finished_at: "29-07-2026 09:55:00", duration_seconds: null },
  });
  assert.equal(row.last_run.exit_code, 127);
  assert.equal(row.last_run.manual, true);
  assert.equal(cronjobSchema.parse({ ...job, running: false, last_run: null }).last_run, null);
  const old = cronjobSchema.parse(job);
  assert.equal(old.running, undefined);
  assert.equal(hasRunHistory([old]), false);
  assert.equal(hasRunHistory([old, { ...old, running: false }]), true);
});

test("the runs response keeps output and its truncation flag", () => {
  const parsed = cronjobRunsResponseSchema.parse({
    runs: [{ status: "succeeded", exit_code: 0, manual: false, overlapped: true, started_at: "29-07-2026 10:12:40", finished_at: "29-07-2026 10:12:41", duration_seconds: 1, output: "ok", output_truncated: true }],
    timezone: "Europe/Berlin",
  });
  assert.equal(parsed.runs[0].output_truncated, true);
  assert.equal(parsed.runs[0].overlapped, true);
  assert.deepEqual(cronjobRunsResponseSchema.parse({ runs: [] }).runs, []);
});

test("a duration reads as seconds, minutes or hours", () => {
  assert.deepEqual(runDuration(3), { key: "seconds", values: { s: 3 } });
  assert.deepEqual(runDuration(125), { key: "minutes", values: { m: 2, s: 5 } });
  assert.deepEqual(runDuration(3840), { key: "hours", values: { h: 1, m: 4 } });
  for (const none of [null, undefined, -1, Number.NaN]) assert.equal(runDuration(none), null);
});

test("Run now asks first, says why it is off, and sends nothing but the start", () => {
  const actions = strip(read("components/cron-jobs/cronjob-row-actions.jsx"));
  assert.match(actions, /const runReason = !canManage \? t\("noPermission"\) : !job\.active \? t\("runNow\.paused"\) : null;/);
  assert.match(actions, /<MenuItemHint hint=\{runReason\}>/);
  // The old Logs link stays for an API without run history.
  assert.match(actions, /\{historySupported \? \([\s\S]*?\) : !canViewLogs \? \(/);
  assert.match(read("lib/api/cronjobs.js"), /api\.post\(`\/cronjobs\/\$\{id\}\/run`\)/);
  const dialog = strip(read("components/cron-jobs/run-now-dialog.jsx"));
  assert.match(dialog, /toast\.error\(apiMessage\(error, t\("runNow\.failed"\)\)\)/);
  assert.match(dialog, /\{job\.command\}/);
});

test("the list re-reads only while something runs", () => {
  const panel = strip(read("components/cron-jobs/cronjobs-panel.jsx"));
  assert.match(panel, /const anyRunning = cronjobs\.some\(\(job\) => job\.running === true\);/);
  assert.match(panel, /if \(!anyRunning\) return undefined;\s*const id = setTimeout\(\(\) => router\.refresh\(\), 5000\);/);
  const sheet = strip(read("components/cron-jobs/run-history-sheet.jsx"));
  assert.match(sheet, /if \(!open \|\| !anyRunning\) return undefined;/);
});

test("history shows a failed read as an error, never as an empty list", () => {
  const sheet = strip(read("components/cron-jobs/run-history-sheet.jsx"));
  assert.match(sheet, /\(error\) => \(signal\?\.aborted \? null : \{ error: apiMessage\(error, t\("failed"\)\) \}\)/);
  assert.match(sheet, /state\.error \? \([\s\S]*?\) : state\.runs\.length === 0 \?/);
});

test("with Last run, the table waits for room for eight columns (1097px in Russian)", () => {
  const s = strip(read("components/cron-jobs/cronjobs-table.jsx"));
  assert.match(s, /return \(\s*<div className="@container">\s*<div className=\{cardsClass\}>/);
  assert.match(s, /<div className=\{tableClass\}>\s*<DataTable/);
  assert.match(s, /historySupported \? "hidden @min-\[69rem\]:block"/);
});

test("every new string exists in all 8 languages", () => {
  for (const locale of LOCALES) {
    const c = JSON.parse(read(`messages/${locale}.json`)).cronJobs;
    for (const [section, keys] of Object.entries({
      lastRun: ["column", "notYet", "succeeded", "failed", "failedExit", "running", "interrupted", "interruptedHint", "manual", "took"],
      duration: ["seconds", "minutes", "hours"],
      runNow: ["action", "paused", "title", "description", "confirm", "starting", "started", "failed"],
      history: ["action", "title", "description", "descriptionNoZone", "refresh", "fullLog", "retry", "failed", "empty", "started", "finished", "overlapped", "showOutput", "hideOutput", "noOutput", "truncated", "copyOutput"],
    })) {
      for (const key of keys) assert.ok(c[section]?.[key], `${locale}: ${section}.${key}`);
    }
  }
});
