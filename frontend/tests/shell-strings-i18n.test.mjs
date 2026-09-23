import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Three of the four strings on the theme menu sit inside a CLOSED dropdown, so
 * a sweep that reads the rendered page never sees them — they were English in
 * all eight locales and a full pass over the Dashboard missed them twice.
 *
 * The sidebar toggle was the same shape: a shadcn primitive that shipped with
 * "Toggle Sidebar" written into it. The wrapper's translated tooltip does not
 * become the accessible name; it competes with the sr-only span underneath, so
 * a screen reader announced English whatever the page said.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);
const messages = Object.fromEntries(
  LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]),
);

test("no English is baked into the shell chrome", () => {
  for (const [file, strings] of [
    ["components/ui/sidebar.jsx", ['"Toggle Sidebar"']],
    ["components/theme-toggle.jsx", ['"Toggle theme"', ">Light<", ">Dark<", ">System<"]],
  ]) {
    const src = read(file).replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
    for (const literal of strings) {
      assert.doesNotMatch(src, new RegExp(literal.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")), `${file}: ${literal}`);
    }
  }
});

test("the sidebar toggle names itself in the reader's language, in both places", () => {
  /*
   * The trigger's sr-only span IS the accessible name; the rail's string is a
   * `title`, i.e. a visible tooltip. Both were English.
   */
  const src = read("components/ui/sidebar.jsx");
  assert.match(src, /function useToggleLabel\(\)/);
  assert.match(src, /<span className="sr-only">\{label\}<\/span>/);
  assert.match(src, /aria-label=\{label\}/);
  assert.match(src, /title=\{label\}/);
  for (const locale of LOCALES) {
    for (const key of ["expandSidebar", "collapseSidebar"]) {
      assert.ok(messages[locale].common[key]?.trim(), `${locale} common.${key}`);
    }
  }
});

test("every item in the theme menu is translated, including the closed ones", () => {
  const src = read("components/theme-toggle.jsx");
  for (const key of ["label", "light", "dark", "system"]) {
    assert.match(src, new RegExp(`t\\("${key}"\\)`), key);
  }
  for (const locale of LOCALES) {
    const theme = messages[locale].common?.theme;
    assert.ok(theme, `${locale} common.theme`);
    for (const key of ["label", "light", "dark", "system"]) {
      assert.ok(theme[key]?.trim(), `${locale} common.theme.${key}`);
    }
  }
  // Russian splits "System": the noun for a system record vs an adjective
  // agreeing with тема. Recorded in one-voice's MANY_MEANINGS with the reason.
  assert.match(read("scripts/one-voice.mjs"), /System: \{[\s\S]*?locales: \["ru"\]/);
});
