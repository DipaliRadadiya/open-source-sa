import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";
import { activityRetentionSchema } from "../lib/schemas/activity.js";
import { retentionCutoff } from "../lib/activity-log/retention.js";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const card = read("components/admin/activity/retention-button.jsx");

// 2bd19e7a (Dipali, 29 Oct): GET/PUT /admin/activity-log/retention.
test("the schema keeps null (keep everything) and the counts", () => {
  const r = activityRetentionSchema.parse({ retention: { months: null, choices: [3, 6, 12, 24], entries: 12840, oldest_at: "14-03-2026 09:12:00" } }).retention;
  assert.equal(r.months, null);
  assert.deepEqual(r.choices, [3, 6, 12, 24]);
  assert.equal(r.entries, 12840);
  assert.equal(activityRetentionSchema.parse({ retention: { months: 12 } }).retention.choices.length, 4);
});

test("PUT always sends months, null for Never", () => {
  assert.match(read("lib/api/activity-log.js"), /api\.put\("\/admin\/activity-log\/retention", \{ months \}\)/);
  assert.match(card, /updateActivityRetention\(value === NEVER \? null : Number\(value\)\)/);
});

test("the choices come from the API, 12 is marked recommended, and Forever comes first", () => {
  assert.match(card, /saved\.choices\.map\(\(months\) =>/);
  assert.match(card, /const RECOMMENDED = 12;/);
  assert.match(card, /<SelectItem value=\{NEVER\}>\{t\("forever"\)\}<\/SelectItem>\s*\{saved\.choices/);
});

test("the header button shows the setting; the window says what the choice would delete", () => {
  assert.match(card, /saved\.months == null \? t\("keptForever"\) : t\("kept", \{ period: monthsLabel\(saved\.months\) \}\)/);
  assert.match(card, /oldest && cutoff && oldest < cutoff\s*\? \{ text: t\("previewDelete"/);
  assert.match(card, /<p aria-live="polite"/);
});

test("the cutoff is now minus whole months, a missing day falling back to the month's last", () => {
  assert.equal(retentionCutoff(Date.UTC(2026, 10, 20, 12), 12).toISOString(), "2025-11-20T12:00:00.000Z");
  assert.equal(retentionCutoff(Date.UTC(2026, 4, 31, 0), 3).toISOString(), "2026-02-28T00:00:00.000Z");
  assert.equal(retentionCutoff(Date.UTC(2026, 0, 15, 0), 3).toISOString(), "2025-10-15T00:00:00.000Z");
});

test("only the admin page shows it, and an unanswered read is said so", () => {
  const page = read("app/admin/activity-log/page.jsx");
  assert.match(page, /getActivityRetention\(\)/);
  assert.match(page, /actions=\{retention\.retention \? <RetentionButton retention=\{retention\.retention\} \/> : null\}/);
  assert.match(page, /t\("retention\.loadFailed"\)/);
  assert.doesNotMatch(read("app/(app)/activity-log/page.jsx").toString(), /RetentionButton/);
});

test("the wording is in all 8 languages", () => {
  for (const locale of LOCALES) {
    const r = JSON.parse(read(`messages/${locale}.json`)).activity.retention;
    for (const key of ["title", "description", "label", "months", "recommended", "forever", "keptForever", "kept", "previewNever", "previewDelete", "previewNone", "previewEmpty", "inLog", "hint", "entries", "entriesSince", "save", "saved", "saveFailed", "loadFailed"]) assert.ok(r[key], `${locale}: ${key}`);
  }
  const en = JSON.parse(read("messages/en.json")).activity.retention;
  // "Keep … for", so "Forever" reads as an answer (Krishna, 20 Nov).
  assert.equal(en.label, "Keep activity log entries for");
  assert.equal(en.hint, "Checked once a day. Entries from the last 30 days are always kept.");
});
