import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("SFTP/FTP downloads are prepared when the API says so, by reason, never by message", () => {
  const button = read("components/backups/download-backup-button.jsx");
  assert.match(button, /error\?\.response\?\.status === 409 && error\.response\.data\?\.reason === "prepare_required"/);
  assert.match(button, /await prepareBackupDownload\(backup\.id\)/);
  const api = read("lib/api/backups.js");
  assert.match(api, /api\.post\(`\/backups\/\$\{backupId\}\/download\/prepare`\)/);
  assert.match(api, /api\.get\(`\/backups\/\$\{backupId\}\/download\/prepare`, \{ signal \}\)/);
});

test("the availability check never counts as a download", () => {
  const dialog = read("components/backups/prepare-download-dialog.jsx");
  // A range from byte 0, or a HEAD, is logged as backup.downloaded (oss-api, 22 Oct).
  assert.match(dialog, /headers: \{ Range: "bytes=1-1" \}/);
  assert.doesNotMatch(dialog, /bytes=0-/);
  assert.doesNotMatch(dialog, /method: "HEAD"/);
  // Busy says when to retry; an expired link prepares a new copy by itself.
  assert.match(dialog, /probe\.status === 503/);
  assert.match(dialog, /probe\.status === 410\) \{\n\s+await start\(\);/);
  // Polled only while a copy is being made.
  assert.match(dialog, /if \(!open \|\| !preparing\) return undefined;/);
});
