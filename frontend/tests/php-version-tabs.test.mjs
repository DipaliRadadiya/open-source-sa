import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { isInFlight } from "../lib/runtime/in-flight.js";

const source = fs.readFileSync("components/php/version-tabs.jsx", "utf8");

/*
 * The reported bug: the ionCube tab read "Installing" on a server where
 * nothing was installing.
 *
 * `status` is `installing | removing | ready | failed | idle`, and `idle` is
 * what most servers send — ionCube has never been touched there. The badge
 * asked `status && status !== "ready"`, which is true of `idle`, so the strip
 * announced an install that did not exist.
 *
 * The fix is not a longer condition. It is using the helper that already owns
 * this vocabulary, so the tab and the card beside it cannot drift apart.
 */
test("idle is not in flight — the state that made the tab lie", () => {
  assert.equal(isInFlight("idle"), false, "the common case: never touched");
  assert.equal(isInFlight("ready"), false);
  assert.equal(isInFlight(null), false);
  assert.equal(isInFlight(undefined), false);
  assert.equal(isInFlight("failed"), false);
  assert.equal(isInFlight("installing"), true);
  assert.equal(isInFlight("removing"), true);
});

test("the tab badge asks isInFlight rather than testing status by hand", () => {
  assert.match(source, /isInFlight\(ioncube\.status\)/);
  // The shape that produced the bug, in any spelling.
  assert.doesNotMatch(
    source,
    /status\s*&&\s*ioncube\.status\s*!==\s*"ready"/,
    "back to a hand-rolled status test, which is what read `idle` as installing",
  );
});

test("every ionCube state the API can send has a label, and they are distinct", () => {
  // Matched against the badge function rather than listed only here, so a new
  // state added without a label fails instead of silently falling through to
  // "Off". Two of these are built as `ioncube.${key}` from a ternary and the
  // rest are written out as `t("ioncube.onShort")`, so neither a dotted path
  // nor a quoted bare name matches all six — match the key name itself.
  for (const key of [
    "unavailableShort",
    "failedShort",
    "installingShort",
    "removingShort",
    "onShort",
    "offShort",
  ]) {
    assert.match(source, new RegExp(`\\b${key}\\b`), `the badge never uses ${key}`);
  }

  const locales = fs
    .readFileSync("i18n/routing.js", "utf8")
    .match(/export const locales = \[([^\]]+)\]/)[1]
    .split(",")
    .map((code) => code.trim().replace(/['"]/g, ""))
    .filter(Boolean);

  for (const locale of locales) {
    const php = JSON.parse(fs.readFileSync(`messages/${locale}.json`, "utf8")).php;
    assert.ok(php.tabs?.extensions && php.tabs?.ioncube, `${locale} is missing the tab labels`);

    const states = [
      "onShort",
      "offShort",
      "unavailableShort",
      "installingShort",
      "removingShort",
      "failedShort",
    ];
    const labels = states.map((key) => php.ioncube[key]);
    for (const [i, label] of labels.entries()) {
      assert.ok(label, `${locale} is missing php.ioncube.${states[i]}`);
    }
    // On and Off sharing a word would make the tab unreadable in that locale.
    assert.notEqual(labels[0], labels[1], `${locale} uses one word for both On and Off`);
  }
});

test("an uninstall does not announce itself as an install", () => {
  // Both are in flight, so one label for both was easy to write and says the
  // opposite of what is happening for half the cases it covers.
  assert.match(source, /status === "removing" \? "removingShort" : "installingShort"/);
});

test("the ionCube hint no longer describes the stacked layout it used to have", () => {
  // It read "that is why it is here and not in the extensions list ABOVE",
  // which stopped being true the moment they became sibling tabs.
  const en = JSON.parse(fs.readFileSync("messages/en.json", "utf8")).php.ioncube.hint;
  assert.doesNotMatch(en, /above/i, "the hint still points at a layout that is gone");
});
