import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const CARD = readFileSync(
  new URL("../components/node/version-summary.jsx", import.meta.url),
  "utf8",
);
const SCHEMA = readFileSync(new URL("../lib/schemas/node.js", import.meta.url), "utf8");
const EN = JSON.parse(readFileSync(new URL("../messages/en.json", import.meta.url), "utf8"));

test("the button does not carry a version number", () => {
  /*
   * It read "Update npm (11.12.1)", which is the version INSTALLED — but in a
   * button it reads as the version you would get. So updating an npm that was
   * already current changed nothing on screen and looked like a failure. That
   * is the reported bug; the installed version is a fact about the card, not
   * the button's payload.
   */
  assert.equal(EN.node.npm.action, "Update npm");
  assert.doesNotMatch(EN.node.npm.action, /\{version\}|\{current\}|\{latest\}/);
  assert.match(CARD, /t\("npm\.action"\)/, "the version is back inside the button label");
});

test("the installed npm is shown as a fact", () => {
  assert.match(EN.node.npm.installed, /\{version\}/);
  assert.match(CARD, /t\("npm\.installed", \{ version: npm \}\)/);
});

test("the comparison is the API's, not a string compare here", () => {
  /*
   * The backend sends `npm_update_available` precisely because this is a
   * semver question: as text, '9.8.1' sorts after '10.2.4', so a client doing
   * it itself reports an upgrade as a downgrade and hides a real one.
   */
  assert.match(SCHEMA, /npm_update_available: z\.boolean\(\)\.nullish\(\)/, "the flag is no longer declared");
  assert.match(
    CARD,
    /typeof version\.npm_update_available === "boolean"\s*\?\s*version\.npm_update_available/,
    "the card no longer prefers the API's own comparison",
  );
});

test("a false flag with no latest is unknown, not up to date", () => {
  /*
   * The trap. `updateAvailable()` returns FALSE when the catalog is empty —
   * a box with no egress, or one whose daily refresh has not run. Trusting the
   * boolean alone would disable the button permanently there and npm could
   * never be updated from the panel again. Verified in a real build: with
   * `npm_update_available: false` and `npm_latest: null`, the button stays
   * enabled and the badge claims nothing.
   */
  assert.match(
    CARD,
    /const npmKnown = Boolean\(npm && npmLatest\);/,
    "the card no longer requires a known latest before claiming anything",
  );
  assert.match(
    CARD,
    /const npmCurrent = npmKnown && !npmBehind;/,
    "'already current' can be claimed without a latest again",
  );
  assert.match(CARD, /const npmBehind = !npmKnown\s*\?\s*false/, "an unknown latest no longer reads as 'behind'");
});

test("an API without the flag still works", () => {
  // Inequality is weaker than semver, but it cannot invent an update that is
  // not there — and it is only reached when the flag is absent entirely.
  assert.match(CARD, /npm !== npmLatest/, "the pre-flag fallback is gone");
});
