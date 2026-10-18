import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { blocksVolumesOnly, withoutVolumesOnly } from "../lib/backups/volume-availability.js";
import { backupTargetResponseSchema } from "../lib/schemas/backup.js";

// S-02: "Volumes" was offered, and saved, for a container site with no volumes
// (IT-Tools), and every run then failed. The API now refuses it; the form blocks it.
const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");
const LOCALES = ["de", "en", "es", "fr", "hi", "ja", "pt", "ru"];

test("only a known 'no volumes' blocks Volumes; unknown stays silent", () => {
  assert.equal(blocksVolumesOnly(false), true);
  assert.equal(blocksVolumesOnly(true), false);
  assert.equal(blocksVolumesOnly(null), false);
  assert.equal(blocksVolumesOnly(undefined), false);
});

test("a saved Volumes target of a site with none moves to the one that works", () => {
  assert.equal(withoutVolumesOnly("volumes", false), "volumes_config");
  assert.equal(withoutVolumesOnly("volumes", null), "volumes");
  assert.equal(withoutVolumesOnly("volumes", true), "volumes");
  // The other container types still work on such a site.
  assert.equal(withoutVolumesOnly("volumes_config", false), "volumes_config");
  assert.equal(withoutVolumesOnly("config", false), "config");
});

test("has_volumes survives the response schema, and an older API reads as unknown", () => {
  assert.equal(backupTargetResponseSchema.parse({ backup_target: null, has_volumes: false }).has_volumes, false);
  assert.equal(backupTargetResponseSchema.parse({ backup_target: null, has_volumes: true }).has_volumes, true);
  assert.equal(backupTargetResponseSchema.parse({ backup_target: null }).has_volumes, null);
});

test("the form shows Volumes disabled with the reason, and the page feeds it", () => {
  const fields = read("components/backups/backup-settings-fields.jsx");
  assert.match(fields, /option === "volumes" && noVolumes\s*\?\s*t\("noVolumes\.blocked"\)/);
  assert.match(fields, /const noVolumes = container && blocksVolumesOnly\(hasVolumes\);/);

  assert.match(read("lib/backups/get-backups.js"), /hasVolumes: result\.data\?\.has_volumes \?\? null/);
  assert.match(read("app/(app)/applications/[application]/backups/page.jsx"), /hasVolumes=\{hasVolumes\}/);
  assert.match(read("components/applications/backups/backups-panel.jsx"), /hasVolumes=\{hasVolumes\}/);
  assert.match(read("components/backups/setup-backups-dialog.jsx"), /hasVolumes=\{hasVolumes\}/);
});

test("the reason is translated in every locale", () => {
  for (const locale of LOCALES) {
    const messages = JSON.parse(read(`messages/${locale}.json`));
    const reason = messages.backups.form.noVolumes?.blocked;
    assert.equal(typeof reason, "string", locale);
    assert.ok(reason.length > 0, locale);
  }
});
