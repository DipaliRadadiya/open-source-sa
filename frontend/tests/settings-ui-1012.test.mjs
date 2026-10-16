import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { memoryBytes, memoryInput } from "../lib/settings/redis-memory.js";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
const LOCALES = ["en", "es", "de", "fr", "pt", "ja", "ru", "hi"];

// Server Settings polish, 12 Oct (Krishna).

test("every settings card shows its icon, and colour lives in the chip, not the title", () => {
  const row = read("components/settings/setting-row.jsx");
  assert.match(row, /export function Section\(\{\s*icon: Icon,/);
  // Steel UI (14 Oct): the chip is a toned square; the colour still lives in the chip.
  assert.match(row, /destructive \? "border-destructive\/45 bg-destructive\/10 text-destructive" : "border-primary\/45 bg-primary\/10/);
  assert.doesNotMatch(row, /destructive && "text-destructive"/, "a red title reads as an error");
});

test("the clock switch spans the card under name and timezone", () => {
  assert.match(read("components/settings/general-form.jsx"), /toggle\s*\n\s*\/\/[^\n]*\n\s*\/\/[^\n]*\n\s*wide\s*\n\s*label=\{t\("ntp"\)\}/);
});

test("SSH answers are cards, one question per row, and the port has its own ruled row", () => {
  const ssh = read("components/settings/ssh-form.jsx");
  assert.equal((ssh.match(/variant="card"/g) ?? []).length, 2);
  assert.match(ssh, /className="grid gap-2 @md\/section:grid-cols-2"/);
  assert.match(ssh, /className="grid gap-2 @xl\/section:grid-cols-3"/);
  // Beside the cards the port read as a third sign-in choice; a rule sets it apart, and it
  // keeps the label-on-top, one-column shape every other field has (Krishna, 12 Oct).
  assert.match(ssh, /<hr className="border-border" \/>\s*\n\s*<FormField\s*\n\s*control=\{form\.control\}\s*\n\s*name="port"/);
  assert.match(ssh, /<Row\s*\n\s*label=\{t\("port\.label"\)\}/);
  const order = ["password_authentication", "permit_root_login", "port"].map((n) => ssh.indexOf(`name="${n}"`));
  assert.ok(order[0] < order[1] && order[1] < order[2], "fields out of order");
});

test("Redis shows its limit in words a person uses, not raw bytes", () => {
  assert.equal(memoryInput("268435456"), "256mb");
  assert.equal(memoryInput("1073741824"), "1gb");
  assert.equal(memoryInput("0"), "0");
  assert.equal(memoryInput("256mb"), "256mb");
  assert.equal(memoryInput("1000"), "1000", "not a whole unit: left as it is");
  assert.equal(memoryBytes("256mb"), 268435456);
  assert.equal(memoryBytes("nonsense"), null);
  const form = read("components/settings/redis-form.jsx");
  assert.match(form, /maxmemory: memoryInput\(redis\?\.maxmemory \?\? "0"\)/);
  assert.match(form, /t\(`redis\.policies\.\$\{policy\}`\)/);
  assert.doesNotMatch(form, /className="w-full font-mono"/, "the policy is shown in words now");
  // The code name is for the open list only; in the closed box it split the label (Krishna, 12 Oct).
  assert.match(form, /in-\[\.redis-policy-trigger\]:hidden/);
  assert.match(form, /<SelectTrigger className="redis-policy-trigger /);
});

test("swap and Redis usage carry a bar", () => {
  assert.match(read("components/settings/swap-form.jsx"), /<Progress\s*\n\s*value=\{Math\.min\(100, \(swap\.used \/ swap\.size\) \* 100\)\}/);
  assert.match(read("components/settings/redis-form.jsx"), /value=\{Math\.min\(100, \(redis\.memory_used \/ limitBytes\) \* 100\)\}/);
});

test("Install security updates sits on the right of the note it answers, filled; Next restart only when scheduled", () => {
  const card = read("components/settings/maintenance-card.jsx");
  assert.match(card, /frame=\{\(parts\) => <UpdateStatus updates=\{updates\} \{\.\.\.parts\} \/>\}/);
  assert.match(card, /\{action \? <span className="ms-auto">\{action\}<\/span> : null\}/);
  const action = card.slice(card.indexOf("const action = ("), card.indexOf("const meta = ("));
  assert.match(action, /variant="default"/, "the main action is filled, not outlined on the tinted note");
  assert.match(card, /wide=\{!enabled\}/);
  assert.match(card, /\{enabled \? \(\s*<InfoRow label=\{t\("schedule\.nextLabel"\)\}>/);
});

test("the Redis password's buttons sit beside the field", () => {
  const form = read("components/settings/redis-form.jsx");
  assert.match(form, /<div className="grid gap-x-6 gap-y-2 @3xl\/section:grid-cols-2 @3xl\/section:items-center">\s*<div className="min-w-0">/);
  assert.match(form, /<div className="flex shrink-0 flex-wrap items-center gap-2">/);
});

test("every locale names all eight Redis policies in plain words", () => {
  const POLICIES = ["noeviction", "allkeys-lru", "allkeys-lfu", "allkeys-random", "volatile-lru", "volatile-lfu", "volatile-random", "volatile-ttl"];
  for (const locale of LOCALES) {
    const redis = JSON.parse(read(`messages/${locale}.json`)).settings.performance.redis;
    for (const p of POLICIES) assert.ok(redis.policies?.[p], `${locale} redis.policies.${p}`);
    assert.doesNotMatch(redis.policyHint, /allkeys|volatile/, `${locale} hint still speaks Redis config`);
  }
});
