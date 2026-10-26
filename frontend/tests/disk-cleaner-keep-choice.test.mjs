import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { keepOptions, sizeFor } from "../lib/disk-cleaner/keep-days.js";
import { cleanerPreviewSchema, cleanerScheduleSchema, cleanerRunSchema } from "../lib/schemas/disk-cleaner.js";

const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

// Live on 23.172.120.34: the journal freed 0 B at "keep 7" and 24 MB at "delete all".
const journal = {
  key: "journal", label: "System journal", available: true, safe: true, keeps_days: true,
  reclaimable: 0, reclaimable_human: "0 B",
  reclaimable_by_keep_days: [
    { keep_days: 0, reclaimable: 25165824, reclaimable_human: "24 MB" },
    { keep_days: 7, reclaimable: 0, reclaimable_human: "0 B" },
  ],
};
const orphans = { key: "apt_orphans", label: "Unused packages", available: true, safe: false, keeps_days: false, reclaimable: 1048576, reclaimable_human: "1 MB", reclaimable_by_keep_days: [] };

test("each row is priced for the chosen keep, not the no-choice size", () => {
  assert.deepEqual(sizeFor(journal, 0), { bytes: 25165824, human: "24 MB" });
  assert.deepEqual(sizeFor(journal, 7), { bytes: 0, human: "0 B" });
  // A category the choice does not apply to always frees the whole thing.
  assert.equal(sizeFor(orphans, 7).bytes, 1048576);
  // No choice (a schedule saved before it) is the old size.
  assert.equal(sizeFor(journal, null).bytes, 0);
});

test("the choices and the recommended one come from the API, with 7 days as the fallback", () => {
  assert.deepEqual(keepOptions(null), { choices: [0, 1, 3, 7, 14, 30], recommended: 7 });
  assert.deepEqual(keepOptions({ choices: [0, 3], recommended: 3 }), { choices: [0, 3], recommended: 3 });
});

test("the schemas keep the new fields instead of stripping them", () => {
  const preview = cleanerPreviewSchema.parse({
    disk: { path: "/", total: 1, used: 1, free: 0, percent: 50 },
    categories: [journal],
    keep_days: { choices: [0, 1, 3, 7, 14, 30], recommended: 7 },
  });
  assert.equal(preview.keep_days.recommended, 7);
  assert.equal(preview.categories[0].reclaimable_by_keep_days.length, 2);
  assert.equal(cleanerScheduleSchema.parse({ keep_days: 0 }).keep_days, 0);
  assert.equal(cleanerScheduleSchema.parse({ keep_days: null }).keep_days, null);
  assert.equal(cleanerRunSchema.parse({ id: 1, keep_days: 14 }).keep_days, 14);
});

test("Already clean, the order and the total follow the choice; Delete all warns", () => {
  const panel = read("components/disk-cleaner/cleanup-panel.jsx");
  assert.match(panel, /const cleanable = useMemo\(\(\) => priced\.filter\(\(c\) => c\.bytes > 0\), \[priced\]\);/);
  assert.match(panel, /const cleanRows = useMemo\(\(\) => priced\.filter\(\(c\) => !\(c\.bytes > 0\)\), \[priced\]\);/);
  assert.match(panel, /const chosenBytes = chosen\.reduce\(\(total, c\) => total \+ c\.bytes, 0\);/);
  assert.match(panel, /cleanDisk\(chosen\.map\(\(c\) => c\.key\), keep\)/);
  assert.match(panel, /\{keep === 0 \? \(\s*<Caution tone="destructive"/);
  assert.match(read("lib/api/disk-cleaner.js"), /keep_days: keepDays/);
});

test("the schedule sends its choice and suggests 85% beside Delete all", () => {
  const schedule = read("components/disk-cleaner/schedule-card.jsx");
  assert.match(schedule, /keep_days: keep,/);
  assert.match(schedule, /allowDefault=\{schedule != null && schedule\.keep_days == null\}/);
  assert.match(schedule, /keep === 0 && threshold !== DELETE_ALL_THRESHOLD/);
  assert.match(schedule, /const DELETE_ALL_THRESHOLD = "85";/);
  // On .34 "Use 85%" did nothing: 85 was not on the list yet, so Radix reported "" and the
  // threshold snapped back to "Any". It is on the list now, and "" is ignored.
  assert.match(schedule, /const THRESHOLDS = \["50", "60", "70", "80", "85", "90"\];/);
  assert.match(schedule, /onValueChange=\{\(value\) => value && setThreshold\(value === ANY \? "" : value\)\}/);
});

test("with Delete all the hint does not promise to keep anything", () => {
  for (const file of ["components/disk-cleaner/cleanup-panel.jsx", "components/disk-cleaner/schedule-card.jsx"]) {
    assert.match(read(file), /keep === 0 \? "keep\.hintDeleteAll" : "keep\.hint"/, file);
  }
});

test("the Can be freed tile prices the same choice as the list", () => {
  const page = read("app/(app)/disk-cleaner/page.jsx");
  assert.match(page, /<KeepProvider initial=\{recommended\}>/);
  assert.match(read("components/disk-cleaner/cleanup-panel.jsx"), /const \[keep, setKeep\] = useKeepChoice\(\);/);
  const tile = read("components/disk-cleaner/disk-summary.jsx");
  assert.match(tile, /const \[keep\] = useKeepChoice\(\);/);
  assert.match(tile, /sizeFor\(c, keep\)/);
});
