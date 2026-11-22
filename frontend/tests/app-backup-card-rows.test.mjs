import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const KEYS = ["storedOn", "keeps", "keepsCount", "filesSize", "databaseRow", "noDatabase", "atRisk", "lastFailed"];

// 22 Nov (Krishna): the Backups card stood half empty beside Security. Shrinking it only moved
// the gap; it now carries the facts a reader wants in the same space.
test("set up: where backups go and how many are kept", () => {
  const card = read("components/applications/backup-card.jsx");
  assert.match(card, /<Row icon=\{HardDrive\} label=\{t\("storedOn"\)\}>\{target\.storage_destination_name \?\? "—"\}<\/Row>/);
  assert.match(card, /t\("keepsCount", \{ count: target\.retention_count \}\)/);
});

test("the newest run failing turns Last backup red", () => {
  const card = read("components/applications/backup-card.jsx");
  assert.match(card, /target && backups\[0\]\?\.status === "failed"/);
  assert.match(card, /text-destructive">\{t\("lastFailed", \{ when: backups\[0\]\.created_at_human \}\)\}/);
});

test("not set up: what a backup would hold, and what is at risk", () => {
  const card = read("components/applications/backup-card.jsx");
  assert.match(card, /formatBytes\(application\?\.directory_size_bytes, format\)/);
  // Unknown databases (no permission, failed read) show a dash, never "None attached".
  assert.match(card, /databases === null\s*\?\s*"—"/);
  assert.match(card, /t\("atRisk", \{ name: application\.name \}\)/);
  const page = read("app/(app)/applications/[application]/page.jsx");
  assert.match(page, /databases=\{canSeeDatabases && !siteDatabases\.failed \? siteDatabases\.databases : null\}/);
});

test("the card's new strings exist in every locale", () => {
  for (const locale of LOCALES) {
    const b = JSON.parse(read(`messages/${locale}.json`)).applications.backups;
    for (const key of KEYS) assert.ok(b[key], `${locale}.${key}`);
    assert.match(b.keepsCount, /\{count, plural,/, locale);
    assert.match(b.atRisk, /\{name\}/, locale);
    assert.match(b.lastFailed, /\{when\}/, locale);
  }
});
