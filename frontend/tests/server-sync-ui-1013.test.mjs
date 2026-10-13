import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

// Server Sync, Krishna 13 Oct (on the original layout): real buttons, one filter row,
// a colour and a short meaning per status.
const read = (p) => fs.readFileSync(p, "utf8");

test("Dismissed and Refresh are outline buttons, not ghost text", () => {
  assert.match(read("components/sync/sync-panel.jsx"), /<Button variant="outline" onClick=\{refresh\}/);
  assert.match(read("components/sync/ignored-sheet.jsx"), /<SheetTrigger asChild>\s*<Button variant="outline">/);
});

test("search, type chips and the count share one row", () => {
  const src = read("components/sync/sync-results.jsx");
  assert.match(src, /visible\.length !== items\.length \? \(/);
  assert.ok(src.indexOf("<LocalSearchInput") < src.indexOf("<ToggleGroup") && src.indexOf("<ToggleGroup") < src.indexOf("sm:ms-auto"));
});

test("each status has its own colour and a short meaning in every locale", () => {
  const src = read("components/sync/sync-results.jsx");
  assert.match(src, /found: "default"/);
  assert.match(src, /t\(`outcomeHints\.\$\{item\.action\}`\)/);
  for (const locale of ["en", "es", "de", "fr", "pt", "ja", "ru", "hi"]) {
    const hints = JSON.parse(read(`messages/${locale}.json`)).sync.outcomeHints;
    for (const k of ["found", "adopted", "skipped", "failed"]) assert.ok(hints[k], `${locale} ${k}`);
  }
});
