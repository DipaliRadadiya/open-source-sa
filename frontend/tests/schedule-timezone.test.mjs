import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { backupTargetSchema } from "../lib/schemas/backup.js";
import { cleanerScheduleSchema } from "../lib/schemas/disk-cleaner.js";
import { clockTimeOf } from "../lib/disk-cleaner/next-run.js";

/*
 * Backups and the disk cleaner run on the PROJECT clock, not the server's, and
 * the backend started saying which since 2026-09-17. Two failure modes are
 * pinned here:
 *
 * 1. The field never arriving. Both schemas drop unlisted keys on the parsed
 *    result, so a label added without the schema entry renders nothing — which
 *    is what was already happening to `timezone`.
 *
 * 2. The value being confused with a cron job's. Cron publishes the SERVER
 *    timezone because Linux cron runs on the OS clock; these do not. Same field
 *    name, same format, different meaning — the kind of pair someone later
 *    "fixes" into agreement.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const stripComments = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);

test("a backup target keeps the timezone the API sends", () => {
  const parsed = backupTargetSchema.parse({
    id: 1,
    application_id: 1,
    storage_destination_id: 1,
    type: "full",
    retention_count: 7,
    frequency: "daily",
    schedule_time: "02:00",
    timezone: "Asia/Kolkata",
  });
  assert.equal(parsed.timezone, "Asia/Kolkata");
});

test("a cleaner schedule keeps its timezone and next run", () => {
  const parsed = cleanerScheduleSchema.parse({
    enabled: true,
    frequency: "weekly",
    next_run_at: "20-09-2026 03:00:00",
    next_run_at_human: "in 15 hours",
    timezone: "Asia/Kolkata",
  });
  assert.equal(parsed.timezone, "Asia/Kolkata");
  assert.equal(parsed.next_run_at, "20-09-2026 03:00:00");
});

test("a schedule with no timezone still parses", () => {
  // An older backend, or a target mid-migration. The label is then left out —
  // the screens guard on it — rather than the whole response failing.
  const parsed = backupTargetSchema.parse({
    id: 1,
    application_id: 1,
    storage_destination_id: 1,
    type: "full",
    retention_count: 7,
    frequency: "daily",
  });
  assert.equal(parsed.timezone ?? null, null);
});

test("the cleaner's hidden hour is read off the timestamp, not a Date", () => {
  // The API's format is day-first. `new Date("20-09-2026 03:00:00")` is either
  // invalid or a month-first misreading, and either way a Date would restate
  // the hour in the browser's zone — the exact fault the label prevents.
  assert.equal(clockTimeOf("20-09-2026 03:00:00"), "03:00");
  assert.equal(clockTimeOf("01-12-2026 23:45:00"), "23:45");
  assert.equal(clockTimeOf("20-09-2026 03:00"), "03:00");

  assert.equal(clockTimeOf(null), null);
  assert.equal(clockTimeOf(""), null);
  assert.equal(clockTimeOf("not a time"), null);
  // Out of range rather than silently wrapping into a plausible hour.
  assert.equal(clockTimeOf("20-09-2026 25:00:00"), null);
  assert.equal(clockTimeOf("20-09-2026 03:99:00"), null);

  // Against code, not prose: the helper's own docblock explains why it does
  // NOT use a Date, so matching the raw file would fail on the explanation.
  assert.doesNotMatch(stripComments(read("lib/disk-cleaner/next-run.js")), /new Date\(/);
});

test("the screens name the project's clock, never the server's", () => {
  const form = read("components/backups/backup-settings-fields.jsx");
  const panel = read("components/applications/backups/backups-panel.jsx");
  const card = read("components/disk-cleaner/schedule-card.jsx");

  // Read off the payload, never assumed — a hardcoded fallback would be a
  // specific claim about a clock nothing confirmed.
  // The options endpoint names the same clock for a target not saved yet.
  assert.match(form, /const scheduleTimezone = target\?\.timezone \?\? options\?\.timezone \?\? null/);
  assert.match(panel, /t\(target\.timezone \? `\$\{scheduleKey\}Zone` : scheduleKey/);
  assert.match(card, /nextClockTime && schedule\?\.timezone/);

  assert.doesNotMatch(form, /ServerTimezone|server\.timezone/);
  assert.doesNotMatch(card, /ServerTimezone|server\.timezone/);
});

test("every locale says project time here and server time for cron", () => {
  for (const locale of LOCALES) {
    const m = JSON.parse(read(`messages/${locale}.json`));

    const note = m.backups.form.scheduleTimeZone;
    const cron = m.cronJobs.form.serverTimeNote;
    assert.ok(note, `${locale}: backups.form.scheduleTimeZone missing`);
    assert.match(note, /\{timezone\}/, `${locale}: the note must name the zone`);
    assert.match(cron, /\{timezone\}/, `${locale}: cron's note must name the zone`);
    // The two features run on different clocks, so their notes must not be the
    // same sentence — that is the confusion this change exists to remove.
    assert.notEqual(note, cron, `${locale}: backups and cron claim the same clock`);

    // The old hint asserted the server's clock in every locale. It was wrong
    // everywhere, not just in English.
    const hint = m.backups.form.scheduleTimeHint;
    assert.ok(hint, `${locale}: scheduleTimeHint missing`);

    for (const key of ["howOftenAt", "howOftenAtZone"]) {
      const value = m.backups.application.summary[key];
      assert.ok(value, `${locale}: summary.${key} missing`);
      assert.match(value, /\{frequency\}/, `${locale}: summary.${key} needs {frequency}`);
      assert.match(value, /\{time\}/, `${locale}: summary.${key} needs {time}`);
    }
    assert.match(
      m.backups.application.summary.howOftenAtZone,
      /\{timezone\}/,
      `${locale}: the zone variant must actually name the zone`,
    );

    assert.match(m.diskCleaner.schedule.nextRun, /\{when\}/, `${locale}: nextRun needs {when}`);
    const zoned = m.diskCleaner.schedule.nextRunAtZone;
    for (const token of ["{when}", "{time}", "{timezone}"]) {
      assert.ok(zoned.includes(token), `${locale}: nextRunAtZone needs ${token}`);
    }
  }
});
