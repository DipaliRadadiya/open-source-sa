import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { groupForType } from "../lib/applications/type-categories.js";

/*
 * Reported: search "wordpress", click Tools, WordPress stays on screen.
 *
 * It was deliberate — typing searched the whole catalogue so that someone who
 * typed a name they knew was right never got "no results" because a chip
 * happened to be active. The intent was sound; the execution left the chip
 * lit while being ignored, so the grid showed a CMS application under an
 * active Tools filter and contradicted itself.
 *
 * Both now narrow together, and the stranding case moved to the empty state,
 * which counts what the chip is hiding and offers one click to widen. That is
 * the part worth guarding: drop the count or the button and the original
 * complaint comes straight back in the other direction.
 */

const source = fs.readFileSync("components/applications/site-type-picker.jsx", "utf8");
const code = source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

const LOCALES = fs
  .readFileSync("i18n/routing.js", "utf8")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);

test("the chip narrows the search instead of being bypassed", () => {
  // The pool is filtered by group FIRST, unconditionally — the old code chose
  // the whole catalogue whenever a term was present.
  assert.match(code, /const pool = ordered\.filter\(inGroup\)/);
  assert.match(code, /return term \? pool\.filter\(\(type\) => matchesQuery\(type, term\)\) : pool/);

  // The bypass, in any of the shapes it could come back as.
  assert.doesNotMatch(code, /const pool = term\s*\n?\s*\? ordered/);
});

test("the empty state counts what the chip is hiding", () => {
  assert.match(code, /const hiddenByGroup = useMemo/);
  // Only meaningful when the visible grid is empty and something was typed.
  assert.match(code, /if \(!term \|\| filtered\.length\) return 0;/);
  assert.match(code, /t\("form\.typeNoResultsInCategory"/);
  assert.match(code, /t\("form\.typeSearchAllCategories"\)/);
  assert.match(code, /onClick=\{\(\) => setActiveGroup\("all"\)\}/);
});

test("a term that matches nothing anywhere gets the plain message", () => {
  // Two different dead ends. "nothing in Tools" and "nothing at all" must not
  // collapse into one sentence, or the widen button appears with nothing to
  // widen to.
  assert.match(code, /hiddenByGroup\s*\n?\s*\? t\("form\.typeNoResultsInCategory"/);
  assert.match(code, /: t\("form\.typeNoResults", \{ query \}\)/);
});

test("the empty state names the chip using the chip's own label", () => {
  // A second vocabulary here would name a filter the reader cannot see on
  // screen — the label is taken from the same helper the chips render with.
  assert.match(code, /const groupLabel = \(key\) =>/);
  assert.match(code, /category: groupLabel\(activeGroup\)/);
  assert.match(code, /\{groupLabel\(group\.key\)\}/);
});

test("the categories the empty state can name are real groups", () => {
  // `groupForType` answers with a group key, and the message renders that key
  // through `form.category<Key>`. A key with no string would print raw.
  const en = JSON.parse(fs.readFileSync("messages/en.json", "utf8"));
  const keys = new Set(["popular", "all", "others"]);
  for (const category of ["cms", "ecommerce", "developer", "utility", "automation", "monitoring", "productivity", "business", "education", "marketing", "community", "made-up"]) {
    keys.add(groupForType({ category }));
  }
  for (const key of keys) {
    const name = `category${key.charAt(0).toUpperCase()}${key.slice(1)}`;
    assert.ok(en.applications.form[name], `missing applications.form.${name}`);
  }
});

test("both new strings exist in every locale with their placeholders", () => {
  for (const locale of LOCALES) {
    const m = JSON.parse(fs.readFileSync(`messages/${locale}.json`, "utf8"));
    const inCategory = m.applications.form.typeNoResultsInCategory;
    assert.ok(inCategory, `${locale}: typeNoResultsInCategory missing`);
    for (const token of ["{query}", "{category}"]) {
      assert.ok(inCategory.includes(token), `${locale}: needs ${token}`);
    }
    // Plural, because "1 match" and "3 matches" are different sentences in
    // most of these languages and several inflect the verb too.
    assert.match(inCategory, /\{count,\s*plural,/, `${locale}: count must be a plural`);
    assert.ok(m.applications.form.typeSearchAllCategories, `${locale}: widen button missing`);
  }
});
