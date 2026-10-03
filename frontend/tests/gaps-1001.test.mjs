import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("a failed unban keeps its confirm open and says which address failed", () => {
  const card = read("components/fail2ban/banned-card.jsx");
  const onUnban = card.slice(card.indexOf("async function onUnban("), card.indexOf("async function onUnbanAll("));
  // Closed on success and on 404 only, never in `finally`.
  assert.doesNotMatch(onUnban.slice(onUnban.indexOf("finally")), /setUnbanConfirm\(null\)/);
  assert.match(onUnban, /t\("banned\.unbanFailed", \{ ip: ban\.ip \}\)/);
  assert.match(card, /t\("banned\.unbanAllFailed"\)/);
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const b = JSON.parse(read(`messages/${l}.json`)).fail2ban.banned;
    assert.match(b.unbanFailed, /\{ip\}/, l);
    assert.ok(b.unbanAllFailed, l);
  }
});

test("Enter in the automatic-cleanup dialog saves, like the other dialogs", () => {
  const card = read("components/disk-cleaner/schedule-card.jsx");
  assert.match(card, /asForm\s+onSubmit=\{\(event\) => \{\s*event\.preventDefault\(\);\s*save\(\);/);
  assert.match(card, /<Button type="button" variant="outline" onClick=\{\(\) => setOpen\(false\)\}/);
  assert.match(card, /<Button type="submit" disabled=\{pending \|\| \(enabled && picked\.size === 0\) \|\| thresholdInvalid\}>/);
});

test("a version whose install just started is not offered again before the list catches up", () => {
  const src = read("components/runtime/install-version-button.jsx");
  assert.match(src, /setStarted\(\(current\) => \[\.\.\.current, String\(version\)\]\);/);
  assert.match(src, /const justStarted = started\.filter\(\(v\) => !listed\.has\(v\)\);/);
  assert.match(src, /installOptions\(installable, \[\.\.\.\(Array\.isArray\(installed\) \? installed : \[\]\), \.\.\.justStarted\]\)/);
});

test("firewall and fail2ban layout fixes from the 1024 pass", () => {
  const rules = read("components/firewall/rules-card.jsx");
  // Each filter is as wide as its own choice, so no language can cut one off;
  // the search keeps room for the longest placeholder (fr) and the row wraps below that.
  for (const key of ["enabled", "action", "origin", "sort"]) {
    const i = rules.indexOf(`paramKey="${key}"`);
    assert.match(rules.slice(i, i + 900), /className="w-full sm:w-auto sm:min-w-36 sm:shrink-0"/, key);
  }
  assert.match(rules, /<div className="sm:max-w-xs sm:min-w-56 sm:flex-1">\s*<SearchInput placeholder=\{t\("rules\.search"\)\} \/>/);
  assert.match(rules, /<div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">/);
  const quick = read("components/firewall/quick-add-card.jsx");
  assert.doesNotMatch(quick, /min-w-0 truncate text-sm font-medium">\{title\}/);
  assert.match(quick, /t\("quick\.tileRisky", \{ name: preset\.label \}\)/);
  assert.match(read("components/fail2ban/banned-card.jsx"), /xl:flex-row xl:items-start xl:justify-between/);
});

test("a service restart/stop confirm closes on confirm; only the row shows progress (Krishna, 1 Oct)", () => {
  const src = read("components/services/service-actions.jsx");
  assert.match(src, /onConfirm=\{\(\) => \{\s*const action = confirming;\s*setConfirming\(null\);\s*run\(action\);/);
  const dialog = src.slice(src.indexOf("<ConfirmDialog"));
  assert.doesNotMatch(dialog, /pending=\{busy\}/);
});
