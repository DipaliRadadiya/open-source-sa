import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

// Disk Cleaner tidy-up, Krishna 13 Oct.
const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\{\/\*[\s\S]*?\*\/\}/g, "").replace(/\/\/.*$/gm, "");
const panel = strip(read("components/disk-cleaner/cleanup-panel.jsx"));
const schedule = strip(read("components/disk-cleaner/schedule-card.jsx"));
const runs = strip(read("components/disk-cleaner/runs-card.jsx"));
const LOCALES = ["en", "es", "de", "fr", "pt", "ja", "ru", "hi"];

test("the group tag is gone: it repeated the row name", () => {
  assert.doesNotMatch(panel, /groups\./);
});

test("already-clean items are named in one line, with nothing to tick", () => {
  const clean = panel.slice(panel.indexOf("cleanRows.length ?"));
  const line = clean.slice(0, clean.indexOf("</p>"));
  assert.doesNotMatch(line, /<Checkbox/);
  assert.match(line, /list\.alreadyCleanList/);
  assert.match(line, /cleanRows\.map\(\(category\) => category\.label\)\.join/);
});

test("cleanable items are tiles, two across when there is room, size beside the name", () => {
  assert.match(panel, /grid grid-cols-1 gap-3 @2xl\/items:grid-cols-2/);
  const tile = panel.slice(panel.indexOf("cleanableRows.map"), panel.indexOf("cleanRows.length ?"));
  // The size sits in the same row as the name, inside the tile.
  assert.ok(tile.indexOf("category.label") < tile.indexOf("reclaimable_human"));
  assert.ok(tile.indexOf("reclaimable_human") < tile.indexOf("category.description"));
});

test("the top row is three compact tiles, as on Services", () => {
  const summary = strip(read("components/disk-cleaner/disk-summary.jsx"));
  assert.equal((summary.match(/<Tile\s+compact/g) ?? []).length, 2);
  assert.match(schedule, /<Tile\s+compact/);
  assert.match(read("app/(app)/disk-cleaner/page.jsx"), /@min-\[52rem\]\/tiles:grid-cols-\[minmax\(0,1fr\)_minmax\(0,0\.8fr\)_minmax\(0,1\.3fr\)\]/);
});

test("select all is a checkbox above the list, half-ticked for a partial pick", () => {
  assert.match(panel, /id="clean-all"/);
  assert.match(panel, /allSelected \? true : chosen\.length > 0 \? "indeterminate" : false/);
  assert.ok(panel.indexOf('id="clean-all"') < panel.indexOf("cleanableRows.map"));
});

test("the selection total sits beside Clean up, not in the description", () => {
  const description = panel.slice(panel.indexOf("<CardDescription"), panel.indexOf("</CardDescription>"));
  assert.doesNotMatch(description, /selectedSummary/);
  assert.match(panel.slice(panel.indexOf("bg-muted/30")), /action\.selectedSummary/);
});

test("re-measure and exact folders are header buttons, not underlined links", () => {
  const action = panel.slice(panel.indexOf("<CardAction"), panel.indexOf("</CardAction>"));
  assert.match(action, /<RefreshButton \/>/);
  assert.match(action, /aria-pressed=\{showPaths\}/);
  assert.doesNotMatch(read("components/disk-cleaner/measured-at.jsx"), /<button/);
});

test("automatic cleanup says Off in one word, in every locale", () => {
  for (const locale of LOCALES) {
    const off = JSON.parse(read(`messages/${locale}.json`)).diskCleaner.schedule.summaryOff;
    assert.ok(off.length <= 12 && !/[—:.]\s/.test(off), `${locale}: "${off}" is a sentence again`);
  }
});

test("history is a paged table that names what each run cleaned", () => {
  assert.match(runs, /<Table>/);
  assert.match(runs, /run\.categories \?\? \[\]\)\.map/);
  assert.match(runs, /formatBytes\(freed\[key\], format\)/);
  // Phone gets a stacked list: five columns cut "Freed" off at 390px.
  assert.match(runs, /<ul className="divide-y border-t md:hidden">/);
  assert.match(runs, /<DataTablePagination meta=\{meta\} \/>/);
  const page = read("app/(app)/disk-cleaner/page.jsx");
  assert.match(page, /getCleanerRuns\(runsPage, perPage\)/);
  assert.match(read("lib/disk-cleaner/get-disk-cleaner.js"), /searchParams: \{ page, per_page: perPage \}/);
});

test("schedule form: a threshold list, capitalised options, hidden settings while off", () => {
  // An example "80" in an empty number box read as a value already set.
  assert.doesNotMatch(schedule, /<Input/);
  assert.match(schedule, /value=\{threshold === "" \? ANY : threshold\}/);
  assert.match(schedule, /schedule\.frequencyOption\.\$\{value\}/);
  // Off: the switch and one line, nothing greyed out below it.
  assert.doesNotMatch(schedule, /opacity-50/);
  assert.match(schedule, /\{!enabled \? \(\s*<p className="-mt-3 text-xs text-muted-foreground">/);
  // Whole row ticks, biggest first like the page.
  assert.match(schedule, /htmlFor=\{`schedule-\$\{category\.key\}`\}/);
  assert.match(schedule, /\.sort\(\(a, b\) => \(b\.reclaimable \?\? 0\) - \(a\.reclaimable \?\? 0\)\)/);
  for (const locale of LOCALES) {
    const s = JSON.parse(read(`messages/${locale}.json`)).diskCleaner.schedule;
    assert.match(s.thresholdOver, /\{percent\}/, locale);
    assert.deepEqual(Object.keys(s.frequencyOption), ["hourly", "daily", "weekly", "monthly"], locale);
  }
});
