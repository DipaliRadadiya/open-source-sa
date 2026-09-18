import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const SOURCE = fs.readFileSync("components/settings/maintenance-card.jsx", "utf8");
const LOCALES = ["en", "es", "de", "fr", "pt", "hi", "ja", "ru"];

const messages = Object.fromEntries(
  LOCALES.map((l) => [l, JSON.parse(fs.readFileSync(`messages/${l}.json`, "utf8"))]),
);
const updates = (l) => messages[l].settings.maintenance.updates;

/*
 * Reported twice, from a real box: "34 updates available" sat in the headline
 * beside a button that installed none of them, because apt-check counts every
 * upgradable package while unattended-upgrades installs only from
 * Allowed-Origins. The first fix explained the gap in a note. The decision here
 * went further — remove the number that caused it. Every control on this card
 * acts on security updates alone, so the card reports security updates alone,
 * and there is no longer a contradiction to explain.
 */

test("the card reports the security count, not the total", () => {
  assert.match(
    SOURCE,
    /const security = updates\?\.security_updates_available \?\? null/,
    "the displayed count must come from the security field",
  );

  // `updates_available` must not drive anything in this component any more.
  // Matched on use, not on the word: the comment above explains why the total
  // was dropped, and a test that fails on its own rationale tests nothing.
  assert.doesNotMatch(
    SOURCE,
    /updates\?\.updates_available/,
    "the total must not be read by this card",
  );
});

test("the state and the icon key off the security count", () => {
  assert.match(SOURCE, /if \(security == null && !failed && !unreadable && !neverRun\) return null/);
  assert.match(SOURCE, /: unreadable \|\| security == null/, "unknown-count branch uses security");
  assert.match(SOURCE, /unreadable \|\| security == null \|\| security > 0 \?/, "icon branch uses security");
});

test("the reconciling note is gone, along with its string", () => {
  // It existed only to explain two numbers shown at once.
  assert.doesNotMatch(SOURCE, /securityOnlyNote/);
  for (const l of LOCALES) {
    assert.equal(updates(l).securityOnlyNote, undefined, `${l}: securityOnlyNote should be removed`);
  }
});

test("the run button no longer needs either count", () => {
  assert.match(SOURCE, /function RunSecurityUpdates\(\{ run, canManage \}\)/);
  assert.doesNotMatch(SOURCE, /total=\{updates\?\.updates_available\}/);
});

test("both states name security explicitly, in every locale", () => {
  // The whole point: a user must not have to infer which updates are meant.
  assert.match(updates("en").pending, /security update/);
  assert.match(updates("en").upToDate, /No security updates waiting/);

  for (const l of LOCALES) {
    assert.match(updates(l).pending, /\{count, plural,/, `${l}: pending must take a count`);
    assert.ok(updates(l).upToDate.trim().length > 0, `${l}: empty upToDate`);
    // The old shape took two arguments and is what produced the confusing line.
    assert.doesNotMatch(updates(l).pending, /\{security, plural,/, `${l}: pending still takes security`);
    assert.doesNotMatch(updates(l).pending, /\{total, plural,/, `${l}: pending still takes total`);
  }
});
