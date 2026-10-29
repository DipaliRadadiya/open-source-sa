import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";
import { activityRetentionSchema } from "../lib/schemas/activity.js";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const card = read("components/admin/activity/retention-card.jsx");

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

test("the choices come from the API, 12 is marked recommended, and Never is last", () => {
  assert.match(card, /saved\.choices\.map\(\(months\) =>/);
  assert.match(card, /const RECOMMENDED = 12;/);
  assert.match(card, /<SelectItem value=\{NEVER\}>\{t\("never"\)\}<\/SelectItem>/);
  // The daily-check hint only once something will be deleted.
  assert.match(card, /\{value !== NEVER \? <p className="text-xs leading-relaxed text-muted-foreground">\{t\("hint"\)\}<\/p> : null\}/);
});

test("only the admin page shows it, and an unanswered read is said so", () => {
  const page = read("app/admin/activity-log/page.jsx");
  assert.match(page, /getActivityRetention\(\)/);
  assert.match(page, /retention\.retention \? \(\s*<RetentionCard retention=\{retention\.retention\} \/>/);
  assert.match(page, /t\("retention\.loadFailed"\)/);
  assert.doesNotMatch(read("app/(app)/activity-log/page.jsx").toString(), /RetentionCard/);
});

test("the wording is in all 8 languages, with Dipali's English", () => {
  for (const locale of LOCALES) {
    const r = JSON.parse(read(`messages/${locale}.json`)).activity.retention;
    for (const key of ["title", "description", "label", "months", "recommended", "never", "hint", "now", "entries", "entriesSince", "save", "saved", "saveFailed", "loadFailed"]) assert.ok(r[key], `${locale}: ${key}`);
  }
  const en = JSON.parse(read("messages/en.json")).activity.retention;
  assert.equal(en.label, "Delete activity log entries older than");
  assert.equal(en.hint, "Checked once a day. Entries from the last 30 days are always kept.");
});
