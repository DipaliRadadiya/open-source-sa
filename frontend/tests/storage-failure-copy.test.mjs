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

test("pCloud is not offered anywhere", () => {
  // Withdrawn as a destination (operator, 2026-09-19), and WebDAV with it —
  // pCloud was the only reason the protocol was supported at all. Neither the
  // vendor nor the protocol should be nameable anywhere in the UI.
  //
  // The name must not survive in the picker, the form copy, or the caveat that
  // used to accompany it — a provider nobody can choose should not still be
  // explaining itself.
  const providers = readFileSync("lib/storage/providers.js", "utf8");
  const code = providers.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/[^\n]*/g, "");

  assert.doesNotMatch(code, /pcloud/i);

  for (const locale of readdirSync("messages")) {
    const messages = JSON.parse(readFileSync(`messages/${locale}`, "utf8"));
    assert.doesNotMatch(
      JSON.stringify(messages.storage),
      /pcloud/i,
      `${locale} still mentions pCloud`,
    );
  }
});

