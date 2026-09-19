import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";

/**
 * Every failure category the API can report must have its own sentence.
 *
 * The row used to branch two ways: `invalid_credentials`, and everything else
 * rendered as "the bucket could not be reached". That was fine while those
 * were the only two categories. Once the drivers gained `host_key_mismatch`,
 * a CHANGED HOST KEY — the one failure that can mean somebody else is
 * answering — displayed as a network problem, which is the opposite of what
 * it means and sends the operator to check a firewall.
 *
 * This pins the mapping to the categories the backend actually returns. The
 * list is copied from `StorageDriver::classify()` implementations; if a driver
 * gains a category, this test fails until the copy exists in all 8 locales.
 */
const CATEGORIES = [
  "invalid_credentials",
  "unreachable",
  "host_key_mismatch",
  "invalid_private_key",
  "root_missing",
  "mismatch",
  // Google Drive. `drive_personal` is the one that matters: a service account
  // has no storage on a personal Drive, so the destination can never work —
  // completely different advice from any other failure here.
  "drive_personal",
  "drive_not_shared",
  "drive_folder_missing",
  "drive_not_a_folder",
  "drive_bad_key",
  "drive_quota",
  "drive_incomplete",
  // WebDAV.
  "dav_full",
  "dav_reset",
];

const row = readFileSync("components/integrations/storage/destination-row.jsx", "utf8");

test("the row maps every backend failure category to its own message key", () => {
  for (const category of CATEGORIES) {
    assert.ok(
      new RegExp(`\\b${category}:`).test(row),
      `destination-row.jsx has no FAILURE_KEYS entry for "${category}" — it would render the generic failure`,
    );
  }
});

test("an unknown category falls back to the generic failure, not to unreachable", () => {
  // A category this build has never heard of is not known to be a network
  // fault. Falling back to "could not be reached" is a guess, and guessing is
  // what produced the host-key bug.
  assert.match(row, /\?\?\s*"failed"/);
});

test("every failure message exists in every locale", () => {
  const wanted = [
    "failed",
    "failedCredentials",
    "failedUnreachable",
    "failedHostKey",
    "failedPrivateKey",
    "failedRootMissing",
    "failedMismatch",
    "failedDrivePersonal",
    "failedDriveNotShared",
    "failedDriveFolderMissing",
    "failedDriveNotAFolder",
    "failedDriveBadKey",
    "failedDriveQuota",
    "failedDriveIncomplete",
    "failedDavFull",
    "failedDavReset",
  ];

  const locales = readdirSync("messages").filter((f) => f.endsWith(".json"));
  assert.equal(locales.length, 8, "expected 8 locales");

  for (const file of locales) {
    const messages = JSON.parse(readFileSync(`messages/${file}`, "utf8"));
    const rowCopy = messages.storage.row;

    for (const key of wanted) {
      assert.ok(rowCopy[key], `${file}: storage.row.${key} is missing`);
      // A raw key on screen is worst at exactly the moment something has gone
      // wrong, which is the only moment these render.
      assert.notEqual(rowCopy[key].trim(), "", `${file}: storage.row.${key} is empty`);
      assert.match(
        rowCopy[key],
        /\{when\}/,
        `${file}: storage.row.${key} drops {when}, so the failure loses its date`,
      );
    }
  }
});

test("the host-key message says the connection was stopped, not that the host was unreachable", () => {
  // The distinction is the entire reason this category exists. Worth pinning
  // in the source locale so a later copy edit cannot quietly undo it.
  const en = JSON.parse(readFileSync("messages/en.json", "utf8"));

  assert.match(en.storage.row.failedHostKey, /host key/i);
  assert.doesNotMatch(en.storage.row.failedHostKey, /could not be reached/i);
});

test("the legacy Drive preset is no longer offered for new destinations", () => {
  /*
   * This test used to assert the opposite: that the form warned about the
   * Shared-Drive constraint before anyone pasted a key. That warning existed
   * because a service account has no quota on a personal Drive, so free Gmail
   * users could not use Drive at all and were pointed at B2/R2 instead.
   *
   * OAuth removed the wall, so the sign came down with it. What is asserted now
   * is the thing that would actually hurt somebody: the legacy preset must stay
   * *resolvable* even though it is hidden, because `presetForProvider` falls
   * back to "other" — an S3 preset — and an existing service-account
   * destination would otherwise open the S3 form on edit.
   */
  const providers = readFileSync("lib/storage/providers.js", "utf8");

  assert.match(providers, /value:\s*"google_drive",\s*provider:\s*"google_drive",\s*legacy:\s*true/);
  assert.doesNotMatch(providers, /providers:\s*\{\s*google_drive:/);

  // The picker filters legacy entries; without this the old preset comes back.
  const form = readFileSync("components/integrations/storage/destination-form-fields.jsx", "utf8");
  assert.match(form, /PRESETS\.filter\(\(p\) => !p\.legacy\)/);
});

test("the pCloud preset carries the vendor's own small-files caveat", () => {
  // pCloud's documentation says its WebDAV is for small files with stability
  // that "may have interruptions", and it stops working entirely with 2FA on.
  // A site archive is not a small file, so offering pCloud without saying so
  // would have the panel implying something the vendor does not.
  const providers = readFileSync("lib/storage/providers.js", "utf8");
  assert.match(providers, /pcloud:\s*"help\.pcloud_warning"/);

  const en = JSON.parse(readFileSync("messages/en.json", "utf8"));
  const warning = en.storage.form.help.pcloud_warning;

  assert.match(warning, /small files/i);
  assert.match(warning, /two-factor/i);
});

test("pCloud is the only WebDAV preset offered", () => {
  // Nextcloud/ownCloud and a generic WebDAV option were removed from the
  // picker (operator, 2026-09-14). The webdav PROVIDER stays — it is what
  // pCloud runs on — but the panel offers exactly one way to reach it.
  //
  // Being the only one also makes `presetForProvider("webdav")` unambiguous,
  // so editing a pCloud destination shows the pCloud form and its caveat
  // instead of whichever preset happened to come first.
  const providers = readFileSync("lib/storage/providers.js", "utf8");

  assert.match(providers, /value:\s*"pcloud",\s*provider:\s*"webdav"/);
  assert.doesNotMatch(providers, /value:\s*"nextcloud"/);
  assert.doesNotMatch(providers, /value:\s*"webdav",\s*provider:\s*"webdav"/);
});
