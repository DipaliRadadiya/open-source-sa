import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * The Backups list said a site backs up "Daily" and never at what hour, so
 * answering "when does this run" meant opening each site in turn — on the one
 * screen that exists to compare every site's schedule side by side.
 *
 * Not a decision: coverage-table.jsx was written on 2026-08-07, one day before
 * `schedule_time` reached the frontend schema, and nothing came back to it once
 * the field arrived. The value was sitting on the row unused for a month.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const table = read("components/backups/coverage-table.jsx");
const cards = read("components/backups/coverage-cards.jsx");
const parent = read("components/backups/coverage-card.jsx");

const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);

test("the table prints the hour, formatted the way the rest of the panel does", () => {
  // Through scheduleWhen since the API's options say which frequencies use the
  // hour: hourly uses only the minute and is printed as one.
  assert.match(table, /import \{ scheduleWhen \} from "@\/lib\/backups\/schedule-time"/);
  assert.match(table, /scheduleWhen\(target, options, format\)/);
  // Its own line: measured, the column is 96px at 1024–1280 and
  // "Daily · 2:00 AM" needs 99.
  assert.match(table, /\{time \? <p className="truncate text-xs tabular-nums">\{time\}<\/p> : null\}/);
});

test("a manual target is given no hour to misread", () => {
  // `frequency: "manual"` stores no time, and the API sends none. A stray
  // separator or an empty line would both imply one exists.
  // scheduleWhen answers null for a frequency whose `time` is null (manual);
  // its own test pins that. Both layouts must go through it.
  for (const source of [table, cards]) {
    assert.match(source, /scheduleWhen\(target, options, format\)/);
  }
  assert.match(cards, /\.filter\(Boolean\)\s*\n?\s*\.join\(" · "\)/);
});

test("the phone layout gets the hour too", () => {
  // This column went a month without the hour because nothing went back to it.
  // The cards falling behind the table is the same failure one layout over.
  assert.match(cards, /import \{ scheduleWhen \}/);
  assert.match(cards, /value=\{target \? scheduleFact\(target\) : t\("placeholders\.schedule"\)\}/);
});

test("the timezone is named once, outside the table", () => {
  /*
   * It went in the column header first. Measured before and after: the header
   * is the widest thing in a 96px column, and "Schedule · Asia/Kolkata" pushed
   * the table 96px further into horizontal scroll at both 1024 and 1280. Above
   * the list it costs the table nothing, and the final measurement matches the
   * pre-change baseline exactly.
   */
  assert.match(parent, /const scheduleZones = new Set\(rows\.map\(\(row\) => row\.target\?\.timezone\)\.filter\(Boolean\)\)/);
  assert.match(parent, /scheduleZones\.size === 1 \? \[\.\.\.scheduleZones\]\[0\] : null/);
  assert.match(parent, /t\("timesShownIn", \{ timezone: scheduleTimezone \}\)/);

  // The rejected route must not come back — it is invisible in a green build.
  assert.doesNotMatch(table, /columns\.scheduleIn/);
});

test("no timezone means no caption, not an assumed one", () => {
  // An older backend, or targets that disagree. Asserting UTC over hours it
  // cannot vouch for is the failure this whole thread has been about.
  assert.match(parent, /\{scheduleTimezone \? \(/);
});

test("the caption exists in every locale and names the zone", () => {
  for (const locale of LOCALES) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    const caption = m.backups.coverage.timesShownIn;
    assert.ok(caption, `${locale}: backups.coverage.timesShownIn missing`);
    assert.match(caption, /\{timezone\}/, `${locale}: the caption must name the zone`);
    // The header variant was measured and dropped; leaving it behind would
    // invite someone to wire it back up.
    assert.equal(
      m.backups.coverage.columns.scheduleIn,
      undefined,
      `${locale}: columns.scheduleIn should have been removed`,
    );
  }
});
