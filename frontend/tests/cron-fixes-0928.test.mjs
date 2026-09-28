import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { parseCron, nextRuns, describeCron, serverTimeToEpoch } from "../lib/cron-jobs/schedule.js";

const read = (p) => fs.readFileSync(p, "utf8");
const from = new Date(Date.UTC(2026, 8, 28, 5, 40)); // Mon 28 Sep 2026 05:40
const runs = (e, n = 3) => nextRuns(parseCron(e), from, n).map((d) => d.toISOString().slice(0, 16));

test("schedule: 0 0 2 * * is midnight on the 2nd, not 2 AM daily", () => {
  assert.deepEqual(runs("0 0 2 * *"), ["2026-10-02T00:00", "2026-11-02T00:00", "2026-12-02T00:00"]);
  assert.deepEqual(runs("0 2 * * *"), ["2026-09-29T02:00", "2026-09-30T02:00", "2026-10-01T02:00"]);
  const d = describeCron(parseCron("0 0 2 * *"));
  assert.deepEqual(d.days, [2]);
  assert.deepEqual(d.time, { kind: "at", times: [[0, 0]] });
});

test("schedule: day-of-month OR weekday when both are set, names, 7 = Sunday, macros", () => {
  assert.deepEqual(runs("0 0 1 * 1"), ["2026-10-01T00:00", "2026-10-05T00:00", "2026-10-12T00:00"]);
  assert.deepEqual(runs("0 9 * * mon-fri", 2), ["2026-09-28T09:00", "2026-09-29T09:00"]);
  assert.deepEqual(runs("0 0 * * 7", 1), ["2026-10-04T00:00"]);
  assert.deepEqual(runs("@yearly", 1), ["2027-01-01T00:00"]);
  assert.deepEqual(runs("0 0 29 2 *", 1), ["2028-02-29T00:00"]);
  assert.deepEqual(runs("0 0 31 2 *"), []);
});

test("schedule: what the backend refuses reads as nothing", () => {
  for (const e of ["61 * * * *", "* * * *", "* * * * * *", "@reboot", "a b c d e", "*/0 * * * *", "5-1 * * * *"]) {
    assert.equal(parseCron(e), null, e);
  }
});

test("schedule: sentence pieces", () => {
  assert.deepEqual(describeCron(parseCron("*/5 * * * *")).time, { kind: "everyMinutes", n: 5 });
  assert.deepEqual(describeCron(parseCron("30 * * * *")).time, { kind: "hourly", minute: 30 });
  assert.deepEqual(describeCron(parseCron("0 */2 * * *")).time, { kind: "everyHours", n: 2, minute: 0 });
  assert.deepEqual(describeCron(parseCron("*/10 9-17 * * *")).time, { kind: "everyBetween", n: 10, from: [9, 0], to: [17, 50] });
  assert.deepEqual(describeCron(parseCron("0 9 * * 1-5")).weekdayRange, [1, 5]);
  assert.equal(describeCron(parseCron("5/15 * * * *")).time, null);
});

test("schedule: server wall time to an instant, in the server's zone", () => {
  assert.equal(new Date(serverTimeToEpoch("28-09-2026 05:01:00", "Etc/UTC")).toISOString(), "2026-09-28T05:01:00.000Z");
  assert.equal(new Date(serverTimeToEpoch("28-09-2026 10:31:00", "Asia/Kolkata")).toISOString(), "2026-09-28T05:01:00.000Z");
  assert.equal(serverTimeToEpoch(null, "UTC"), null);
});

test("CJ-1: success is said once the list shows it", () => {
  for (const f of ["cronjob-active-switch", "delete-cronjob-dialog", "create-cronjob-dialog", "edit-cronjob-dialog"]) {
    const s = read(`components/cron-jobs/${f}.jsx`);
    assert.match(s, /refreshThen/, f);
    assert.doesNotMatch(s, /^\s*router\.refresh\(\);/m, f);
  }
});

test("CJ-2: next run counts down from the instant and the list re-reads when due", () => {
  assert.match(read("components/cron-jobs/cronjobs-table.jsx"), /format\.relativeTime\(Math\.max\(epoch, now\.getTime\(\)\), now\)/);
  assert.match(read("components/cron-jobs/cronjobs-panel.jsx"), /setTimeout\(\(\) => router\.refresh\(\), wait\)/);
});

test("CJ-3: menu focus returns to ⋯, and dialogs opened from a menu return to its button", () => {
  assert.match(read("components/cron-jobs/cronjob-row-actions.jsx"), /if \(!openingDialog\.current\) return;/);
  assert.match(read("components/ui/use-return-focus.js"), /opener\.current = menuTriggerOf\(element\) \?\? element/);
});

test("CJ-4: run-as is editable and sent only when changed", () => {
  const s = read("components/cron-jobs/edit-cronjob-dialog.jsx");
  assert.match(s, /<RunAsField form=\{form\}/);
  assert.match(s, /\{ system_user_id: null, username: values\.username\.trim\(\) \}/);
  assert.match(read("lib/schemas/cronjob.js"), /export const updateCronjobSchema = createCronjobSchema;/);
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const m = JSON.parse(read(`messages/${l}.json`)).cronJobs.form;
    assert.equal(m.runAsLocked, undefined, l);
  }
});

test("CJ-5: a job removed elsewhere is reported as gone and the list re-read", () => {
  for (const f of ["cronjob-active-switch", "delete-cronjob-dialog", "edit-cronjob-dialog"]) {
    assert.match(read(`components/cron-jobs/${f}.jsx`), /status === 404[\s\S]{0,120}toast\.info\(t\("toast\.alreadyGone"/, f);
  }
});

test("CJ-6 + preview + badge + hint", () => {
  const f = read("components/cron-jobs/schedule-field.jsx");
  assert.match(f, /data-error=\{selectInvalid\}/);
  assert.match(f, /<FormControl aria-invalid=\{selectInvalid\}>/);
  assert.match(f, /showRawField \? <SchedulePreview expression=\{expression\} timezone=\{timezone\} \/> : null/);
  assert.match(read("components/cron-jobs/cronjobs-table.jsx"), /<Badge variant="warning" className="font-normal">\s*\{t\("paused"\)\}/);
  assert.match(read("components/cron-jobs/command-field.jsx"), /t\.rich\("form\.commandHint"/);
});

test("CJ-7: a per_page the list refuses shows as 10, not a blank box", () => {
  assert.match(read("components/data-table/per-page-select.jsx"), /PER_PAGE_OPTIONS\.includes\(Number\(fromUrl\)\) \? fromUrl : String\(PER_PAGE_OPTIONS\[0\]\)/);
});

test("layout: table only from xl, where its seven columns fit", () => {
  const s = read("components/cron-jobs/cronjobs-table.jsx");
  assert.match(s, /<div className="xl:hidden">/);
  assert.match(s, /<div className="hidden xl:block">/);
  assert.match(s, /max-w-40 truncate/);
});

test("CJ-B2/B3 guard: the form refuses what Linux cron cannot read", async () => {
  const { createCronjobSchema } = await import("../lib/schemas/cronjob.js");
  const base = { name: "x", run_as: "3", username: "", command: "true", active: true };
  const err = (expression) => createCronjobSchema.safeParse({ ...base, expression }).error?.issues.find((i) => i.path[0] === "expression")?.message;
  for (const e of ["0 0 L * *", "0 0 15W * *", "0 0 ? * *", "0 0 * * 1#2", "0 0 LW * *"]) assert.equal(err(e), "cronUnsupported", e);
  for (const e of ["0 0 2 * *", "*/10 9-17 * * mon-fri", "15 3 * jan,jul sun", "0 0 1,15 * *", "5/15 * * * *", "@weekly"]) assert.equal(err(e), undefined, e);
  assert.equal(err("* * * *"), "cronExpression");
  assert.equal(parseCron("0 0 ? * *"), null);
});

test("next run countdown does not trip a hydration error", () => {
  assert.match(read("components/cron-jobs/cronjobs-table.jsx"), /<span className="whitespace-nowrap" suppressHydrationWarning>\{human\}<\/span>/);
});

test("round 2: hourly wording shows example times, sentences wrap, rows keyed by id, last row leaves to the previous page", () => {
  const en = JSON.parse(read("messages/en.json")).cronJobs.preview;
  assert.equal(en.hourly, "every hour ({examples}, …)");
  assert.doesNotMatch(JSON.stringify(en), /on the hour|past the hour/);
  const table = read("components/cron-jobs/cronjobs-table.jsx");
  assert.match(table, /"max-w-44 whitespace-normal"/);
  const dt = read("components/ui/data-table.jsx");
  assert.match(dt, /getRowId: rowId \?\? \(\(row\) => String\(row\.id\)\)/);
  assert.match(dt, /new Set\(data\.map\(\(row\) => row\.id\)\)\.size === data\.length/);
  assert.match(read("hooks/use-refresh.js"), /navigateThen: \(updates, fn\) =>/);
  assert.match(read("components/cron-jobs/cronjobs-panel.jsx"), /prevPage=\{cronjobs\.length === 1 && meta\.current_page > 1 \? meta\.current_page - 1 : null\}/);
  assert.match(read("components/cron-jobs/delete-cronjob-dialog.jsx"), /if \(prevPage\) navigateThen\(/);
  assert.match(read("components/cron-jobs/cronjob-active-switch.jsx"), /statusFilter !== null && statusFilter !== String\(next\)/);
});

test("deep pass: switch keeps focus while saving, each switch named, long names wrap", () => {
  const ps = read("components/ui/pending-switch.jsx");
  assert.match(ps, /disabled=\{disabled\}/);
  assert.match(ps, /onCheckedChange=\{pending \? undefined : onCheckedChange\}/);
  assert.match(read("components/cron-jobs/cronjob-active-switch.jsx"), /aria-label=\{t\("activeFor", \{ name: job\.name \}\)\}/);
  assert.match(read("components/cron-jobs/cronjobs-table.jsx"), /\[overflow-wrap:anywhere\]">\{job\.name\}/);
});
