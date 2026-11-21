import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

import { usageByDestination } from "../lib/storage/usage.js";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const KEYS = ["usedBy", "notUsed", "lastBackup", "lastBackupFailed", "backingUp", "colBackups", "colTest"];

// 21 Nov (Krishna): the Storage row's empty middle now says what backs up to each destination.
test("usage counts the applications per destination and keeps the newest backup", () => {
  const usage = usageByDestination({
    failed: false,
    inUse: [1, 2],
    targets: [1, 1, 2],
    lastBackups: [
      { id: 10, status: "verified", created_at_human: "1 hour ago", storage_destination_id: 1 },
      { id: 12, status: "failed", created_at_human: "5 minutes ago", storage_destination_id: 1 },
      { id: 11, status: "verified", created_at_human: "2 hours ago", storage_destination_id: 2 },
    ],
  });
  assert.equal(usage[1].applications, 2);
  assert.equal(usage[1].lastBackup.id, 12);
  assert.equal(usage[2].applications, 1);
  assert.equal(usage[3], undefined);
});

test("a failed read gives no usage, never 'unused'", () => {
  assert.equal(usageByDestination({ failed: true }), null);
  assert.equal(usageByDestination(null), null);
});

test("the row shows a failed or running last backup, not just its time", () => {
  const row = read("components/integrations/storage/destination-row.jsx");
  assert.match(row, /backup\.status === "failed"/);
  assert.match(row, /BACKUP_IN_FLIGHT\.includes\(backup\.status\)/);
  // The provider's name sits in the badge only; the old right-hand column repeated it.
  assert.doesNotMatch(row, /const facts =/);
});

test("the new storage strings exist in every locale with their placeholders", () => {
  for (const locale of LOCALES) {
    const row = JSON.parse(read(`messages/${locale}.json`)).storage.row;
    for (const key of KEYS) assert.ok(row[key], `${locale}.${key}`);
    assert.match(row.usedBy, /\{count, plural,/, locale);
    for (const key of ["lastBackup", "lastBackupFailed"]) assert.match(row[key], /\{when\}/, `${locale}.${key}`);
  }
});
