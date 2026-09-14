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
