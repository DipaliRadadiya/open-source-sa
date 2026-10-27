import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

import { hasKeepChoice } from "../lib/disk-cleaner/keep-days.js";

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\{\/\*[\s\S]*?\*\/\}/g, "").replace(/\/\/.*$/gm, "");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];

// Review of c85cb9df (27 Oct): three fixes on top of the keep choice screen.
test("an API without the keep choice is told apart from one with it", () => {
  assert.equal(hasKeepChoice({ choices: [0, 1, 3, 7, 14, 30], recommended: 7 }), true);
  for (const value of [null, undefined, {}, { choices: [] }]) assert.equal(hasKeepChoice(value), false);
});

test("an older API gets no dropdown, no choice in the request and no history labels", () => {
  const page = strip(read("app/(app)/disk-cleaner/page.jsx"));
  assert.match(page, /const keepSupported = hasKeepChoice\(data\.keep_days\);/);
  assert.match(page, /<KeepProvider initial=\{keepSupported \? recommended : null\}>/);
  assert.match(page, /<RunsCard [^>]*keepSupported=\{keepSupported\}/);
  assert.match(strip(read("components/disk-cleaner/cleanup-panel.jsx")), /\{keepSupported \? \(\s*<div[^>]*>\s*<Label htmlFor="clean-keep"/);
  assert.match(strip(read("components/disk-cleaner/schedule-card.jsx")), /\{keepSupported \? \(\s*<div className="space-y-2">\s*<Label htmlFor="cleaner-keep"/);
  assert.match(read("components/disk-cleaner/runs-card.jsx"), /!keepSupported \? null : run\.keep_days == null/);
});

test("Delete all names only what is ticked, and the application history only when it goes", () => {
  const panel = strip(read("components/disk-cleaner/cleanup-panel.jsx"));
  assert.match(panel, /t\("keep\.deleteAllItems", \{ items: chosen\.map\(\(c\) => c\.label\)\.join\(", "\) \}\)/);
  assert.match(panel, /chosen\.some\(\(c\) => c\.key === "site_logs"\) \? ` \$\{t\("keep\.deleteAllSiteLogs"\)\}` : null/);
  assert.doesNotMatch(panel, /deleteAllWarning/);
  for (const locale of LOCALES) {
    const keep = JSON.parse(read(`messages/${locale}.json`)).diskCleaner.keep;
    assert.match(keep.deleteAllItems, /\{items\}/, locale);
    assert.ok(keep.deleteAllSiteLogs && keep.scheduleLegacy, locale);
    assert.equal(keep.deleteAllWarning, undefined, locale);
    // The product noun is "application", not "site".
    assert.doesNotMatch(keep.deleteAllItems + keep.deleteAllSiteLogs, /\bsite\b/i, locale);
  }
});
