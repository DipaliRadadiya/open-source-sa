import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const section = read("components/applications/bot-blocker/bot-blocker-section.jsx");
const traffic = read("components/applications/bot-blocker/bot-traffic-card.jsx");

test("one choice shows one count: the policy lists are de-duplicated before anything counts them", () => {
  // Found live: badge 23, button 22, 23 chips (Meta-ExternalAgent twice).
  assert.match(section, /const policies = dedupedPolicies\(sentPolicies\);/);
  assert.match(section, /blocked_count: bots\.length/);
});

test("a saved choice is not called unsaved while the refresh is on its way", () => {
  assert.match(section, /setJustSaved\(\{ policy, blocked, allowed \}\);/);
  assert.match(section, /const base = justSaved \?\? \{ policy: currentPolicy/);
  assert.match(section, /policy !== base\.policy/);
  assert.match(section, /key === base\.policy \?/);
});

test("a server refusal of one entry is shown on that entry", () => {
  assert.match(section, /field\.match\(\/\^\(blocked\|allowed\)\\\.\(\\d\+\)\$\/\)/);
  assert.match(section, /refused=\{refused\.blocked\}/);
  assert.match(section, /refused=\{refused\.allowed\}/);
});

test("long names wrap instead of running out of the card", () => {
  assert.match(section, /"min-w-0 space-y-2 rounded-lg border p-3"/);
  assert.match(section, /break-all whitespace-normal text-primary/);
});

test("removing an exception by keyboard keeps focus in the list", () => {
  assert.match(section, /button\[data-chip-remove\]/);
  assert.match(section, /\?\? inputRef\.current\)\?\.focus\(\)/);
});

test("the traffic table keeps its settings column on screen at 768", () => {
  assert.equal((traffic.match(/xl:table-cell/g) ?? []).length, 2);
  assert.doesNotMatch(traffic, /md:table-cell/);
});

test("the traffic table says what the settings are, not that requests were blocked", () => {
  for (const l of LOCALES) {
    const tr = JSON.parse(read(`messages/${l}.json`)).applications.botBlocker.traffic;
    assert.notEqual(tr.columns.status, { en: "Right now" }[l] ?? null, l);
    assert.match(tr.summary, /\{blocked, plural/, l);
  }
  const en = JSON.parse(read("messages/en.json")).applications.botBlocker.traffic;
  assert.equal(en.columns.status, "Your settings");
  assert.equal(en.blocked, "Block");
  assert.match(en.summary, /from bots you block/);
});

test("Discard also clears a half-typed name and its error", () => {
  assert.match(section, /key=\{`blocked-\$\{editorKey\}`\}/);
  assert.match(section, /key=\{`allowed-\$\{editorKey\}`\}/);
  assert.match(section, /setEditorKey\(\(key\) => key \+ 1\);/);
});

test("the traffic table's headers and bot names wrap on a phone", () => {
  assert.match(traffic, /h-auto px-2 py-2 whitespace-normal sm:px-4">\{t\("columns\.status"\)\}/);
  assert.match(traffic, /bot\.bot\.length > 24 && "min-w-20 break-all whitespace-normal"/);
});
