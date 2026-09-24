import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { backupTargetFormSchema, backupTargetOptionsSchema } from "../lib/schemas/backup.js";
import {
  historySpan,
  minuteOf,
  orderedTypes,
  scheduledFrequencies,
  timeUsage,
  withMinute,
} from "../lib/backups/frequency.js";
import { scheduleWhen } from "../lib/backups/schedule-time.js";

// BackupController::options() in English, as the backend produced it on 2026-09-24.
const OPTIONS = {
  frequencies: [
    { value: "manual", label: "Manual only", time: null, hint: "Runs only when you start it." },
    { value: "hourly", label: "Every hour", time: "minute", hint: "Runs every hour, at the chosen minute." },
    { value: "every_3_hours", label: "Every 3 hours", time: "time", hint: "Runs at the chosen time and every 3 hours around the clock." },
    { value: "every_6_hours", label: "Every 6 hours", time: "time", hint: "Runs at the chosen time and every 6 hours around the clock." },
    { value: "every_12_hours", label: "Every 12 hours", time: "time", hint: "Runs at the chosen time and 12 hours later." },
    { value: "daily", label: "Daily", time: "time", hint: "Runs once a day at the chosen time." },
    { value: "weekly", label: "Weekly", time: "time", hint: "Runs every Sunday at the chosen time." },
    { value: "monthly", label: "Monthly", time: "time", hint: "Runs on the 1st of each month at the chosen time." },
  ],
  default_frequency: "daily",
  types: [
    { value: "filesystem", label: "Files" },
    { value: "database", label: "Database" },
    { value: "full", label: "Files and database" },
  ],
  retention: { min: 1, max: 365 },
  timezone: "UTC",
};

const valid = {
  application_id: 1,
  storage_destination_id: 2,
  type: "full",
  retention_count: 7,
  frequency: "hourly",
  schedule_time: "14:30",
  enabled: true,
};

test("the real payload parses", () => {
  assert.equal(backupTargetOptionsSchema.safeParse(OPTIONS).success, true);
});

test("the form accepts every frequency the API offers, and nothing else", () => {
  const schema = backupTargetFormSchema(OPTIONS);
  for (const { value } of OPTIONS.frequencies) {
    assert.equal(schema.safeParse({ ...valid, frequency: value }).success, true, value);
  }
  assert.equal(schema.safeParse({ ...valid, frequency: "fortnightly" }).success, false);
  // Without options nothing is accepted; the dialog blocks with a reason.
  assert.equal(backupTargetFormSchema(null).safeParse(valid).success, false);
});

test("retention bounds come from the API", () => {
  const schema = backupTargetFormSchema({ ...OPTIONS, retention: { min: 2, max: 10 } });
  assert.equal(schema.safeParse({ ...valid, retention_count: 1 }).success, false);
  assert.equal(schema.safeParse({ ...valid, retention_count: 10 }).success, true);
  assert.equal(schema.safeParse({ ...valid, retention_count: 11 }).success, false);
});

test("which picker each frequency needs, and manual is not in the picker", () => {
  assert.equal(timeUsage(OPTIONS, "hourly"), "minute");
  assert.equal(timeUsage(OPTIONS, "every_6_hours"), "time");
  assert.equal(timeUsage(OPTIONS, "manual"), null);
  assert.equal(timeUsage(null, "daily"), undefined);
  assert.deepEqual(scheduledFrequencies(OPTIONS).map((f) => f.value), [
    "hourly", "every_3_hours", "every_6_hours", "every_12_hours", "daily", "weekly", "monthly",
  ]);
});

test("retention is described in hours below a day", () => {
  assert.deepEqual(historySpan("hourly", 7), { unit: "hours", amount: 7 });
  assert.deepEqual(historySpan("every_3_hours", 4), { unit: "hours", amount: 12 });
  assert.deepEqual(historySpan("every_12_hours", 7), { unit: "days", amount: 4 });
  assert.deepEqual(historySpan("weekly", 4), { unit: "days", amount: 28 });
  assert.equal(historySpan("something_new", 4), null);
});

test("the minute picker changes only the minute", () => {
  assert.equal(minuteOf("14:30"), "30");
  assert.equal(withMinute("14:30", "5"), "14:05");
  assert.equal(withMinute("14:30", "99"), "14:59");
  assert.equal(withMinute("", "7"), "00:07");
});

test("the full backup is offered first", () => {
  assert.deepEqual(orderedTypes(OPTIONS).map((t) => t.value), ["full", "filesystem", "database"]);
});

test("an hourly schedule is shown as a minute, never as one of its hours", () => {
  const format = { dateTime: (d) => `${d.getHours()}:${String(d.getMinutes()).padStart(2, "0")}` };
  assert.deepEqual(scheduleWhen({ frequency: "hourly", schedule_time: "14:30" }, OPTIONS, format), { minute: "30" });
  assert.deepEqual(scheduleWhen({ frequency: "daily", schedule_time: "02:00" }, OPTIONS, format), { time: "2:00" });
  assert.equal(scheduleWhen({ frequency: "manual", schedule_time: "02:00" }, OPTIONS, format), null);
  // Options missing: no guessed hour.
  assert.equal(scheduleWhen({ frequency: "daily", schedule_time: "02:00" }, null, format), null);
});

test("the form takes its lists from the options, not from constants", () => {
  const fields = fs.readFileSync("components/backups/backup-settings-fields.jsx", "utf8");
  const dialog = fs.readFileSync("components/backups/setup-backups-dialog.jsx", "utf8");
  assert.doesNotMatch(fields, /\["daily", "weekly", "monthly"\]/);
  assert.doesNotMatch(fields, /BACKUP_TYPES/);
  assert.match(fields, /scheduledFrequencies\(options\)/);
  assert.match(fields, /orderedTypes\(options\)/);
  assert.match(fields, /options\.default_frequency/);
  assert.match(dialog, /\? t\("blocked\.noOptions"\)/);
  assert.match(dialog, /timeUsage\(options, values\.frequency\) \? \{ schedule_time: values\.schedule_time \}/);
});

test("both pages read the options", () => {
  for (const page of ["app/(app)/backups/page.jsx", "app/(app)/applications/[application]/backups/page.jsx"]) {
    assert.match(fs.readFileSync(page, "utf8"), /getBackupTargetOptions\(\)/, page);
  }
});
