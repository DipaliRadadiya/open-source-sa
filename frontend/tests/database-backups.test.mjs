import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

import { backupSubject } from "../lib/backups/backup-subject.js";
import { restoreBlocker } from "../lib/backups/restore-blocker.js";
import { databaseBackupTargetResponseSchema, backupSchema } from "../lib/schemas/backup.js";

const t = (key) => key;
const verified = { id: 1, type: "database", status: "verified" };

test("a backup names what it is of, before and after that thing is deleted (16 Oct)", () => {
  assert.deepEqual(backupSubject({ ...verified, database_id: 7, database_name: "shop" }), {
    kind: "database",
    name: "shop",
    href: "/databases/7?tab=backups",
    archived: false,
  });
  const gone = backupSubject({ ...verified, database_id: null, database_name: "shop", archived: true });
  assert.equal(gone.kind, "database");
  assert.equal(gone.href, null);
  assert.equal(gone.archived, true);

  const site = backupSubject({ ...verified, type: "full", application_id: 3, application_name: "Shop", application_domain: "shop.test" });
  assert.equal(site.kind, "application");
  assert.equal(site.href, "/applications/3/backups");

  const deletedSite = backupSubject({ ...verified, type: "full", application_id: null, site_name: "old.test", archived: true });
  assert.deepEqual([deletedSite.kind, deletedSite.name, deletedSite.archived], ["application", "old.test", true]);
});

test("off the database's own page, database and deleted-site backups can't be restored, and say why", () => {
  assert.equal(restoreBlocker({ ...verified, database_id: 7, database_name: "shop" }, t), "blocked.onDatabasePage");
  assert.equal(restoreBlocker({ ...verified, database_name: "shop", archived: true }, t), "blocked.databaseDeleted");
  assert.equal(restoreBlocker({ ...verified, type: "full", site_name: "old.test", archived: true }, t), "blocked.siteDeleted");
  // A live site backup is unchanged.
  assert.equal(restoreBlocker({ ...verified, type: "full", application_domain: "shop.test" }, t), null);
});

test("on the database's own page, its backups and archived ones of its engine restore there", () => {
  assert.equal(restoreBlocker({ ...verified, database_id: 7, database_name: "shop" }, t, false, true), null);
  assert.equal(restoreBlocker({ ...verified, database_name: "old", archived: true }, t, false, true), null);
  assert.equal(restoreBlocker({ ...verified, database_id: 7 }, t, true, true), "blocked.alreadyRunning");
  assert.equal(restoreBlocker({ ...verified, status: "failed", database_id: 7 }, t, false, true), "blocked.failed");
});

test("the database schedule response parses with and without a schedule, and for a site's database", () => {
  assert.deepEqual(databaseBackupTargetResponseSchema.parse({ backup_target: null }), { backup_target: null, backed_up_with: null });
  const owned = databaseBackupTargetResponseSchema.parse({ backup_target: null, backed_up_with: { application_id: 3, application_name: "Shop" } });
  assert.equal(owned.backed_up_with.application_id, 3);
  // Older rows and site backups carry none of the new fields.
  assert.equal(backupSchema.parse({ id: 1, type: "full", status: "verified" }).archived, false);
});

test("a database's Backups tab reuses the site panel with database wording", () => {
  const tabs = fs.readFileSync("components/databases/database-tabs.jsx", "utf8");
  assert.match(tabs, /"backups"\]/);
  const panel = fs.readFileSync("components/applications/backups/backups-panel.jsx", "utf8");
  assert.match(panel, /useSubjectTranslations\("backups\.application", "backups\.database", subject\)/);
  const en = JSON.parse(fs.readFileSync("messages/en.json", "utf8"));
  // Unticking "also delete files" no longer deletes the backups.
  assert.match(en.applications.delete.removeFilesOff, /Archived/);
  assert.match(en.databases.delete.backupsKept, /Archived/);
});

test("archived backups have their own tab, and History leaves them out (Krishna, 16 Oct)", () => {
  const tabs = fs.readFileSync("components/backups/backups-tabs.jsx", "utf8");
  assert.match(tabs, /key: "archived", href: "\/backups\/archived"/);
  const history = fs.readFileSync("app/(app)/backups/history/page.jsx", "utf8");
  assert.match(history, /getBackups\(\{ \.\.\.sp, archived: false \}\)/);
  const archived = fs.readFileSync("app/(app)/backups/archived/page.jsx", "utf8");
  assert.match(archived, /archived: true/);
  // No Restore on that tab; download and delete stay.
  const list = fs.readFileSync("components/backups/archived-backups.jsx", "utf8");
  assert.match(list, /showRestore: false/);
  const fetcher = fs.readFileSync("lib/backups/get-backups.js", "utf8");
  assert.match(fetcher, /searchParams\.archived === false \? 0/);
});
