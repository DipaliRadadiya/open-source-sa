import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

import { isTakenName, markTakenName } from "../lib/storage/name-taken.js";
import { createStorageDestinationSchema } from "../lib/schemas/storage.js";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];

// Testers' sheet, 29 Oct: "The name has already been taken." → "This name is already taken. Please choose a different name."
test("a taken name is one another destination already has, whatever the case or spaces", () => {
  assert.ok(isTakenName(" localbackup ", ["LocalBackup", "S3"]));
  assert.ok(!isTakenName("New", ["LocalBackup"]));
  assert.ok(!isTakenName("", [""]));
});

test("only a 422 on the name for a taken name gets the friendlier message", () => {
  const calls = [];
  const form = { setError: (field, value) => calls.push([field, value.message]) };
  const refused = { response: { status: 422, data: { errors: { name: ["The name has already been taken."] } } } };
  markTakenName(refused, form, "LocalBackup", ["LocalBackup"]);
  markTakenName(refused, form, "Other", ["LocalBackup"]);
  markTakenName({ response: { status: 500, data: {} } }, form, "LocalBackup", ["LocalBackup"]);
  assert.deepEqual(calls, [["name", "nameTaken"]]);
  for (const p of ["components/integrations/storage/connect-dialog.jsx", "components/integrations/storage/edit-dialog.jsx"]) assert.match(read(p), /markTakenName\(error, form, /, p);
  assert.match(read("components/integrations/storage/destinations-card.jsx"), /destinations\.filter\(\(destination\) => destination\.id !== editing\.id\)/);
});

test("a bare root (/var/backups, /mnt, /media) is refused on the form; a folder inside it passes", () => {
  const schema = createStorageDestinationSchema("local");
  const issue = (path) => schema.safeParse({ name: "x", config: { path } }).error?.issues?.[0]?.message ?? null;
  for (const root of ["/var/backups", "/var/backups/", "/mnt", "/media//"]) assert.equal(issue(root), "localPathRoot", root);
  for (const ok of ["/var/backups/panel", "/mnt/disk2/backups"]) assert.equal(issue(ok), null, ok);
});

test("the new strings exist in all 8 languages", () => {
  for (const locale of LOCALES) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    assert.ok(m.validation.nameTaken && m.validation.localPathRoot, locale);
    assert.ok(m.storage.form.help.path.includes("/var/backups/panel"), locale);
  }
});
