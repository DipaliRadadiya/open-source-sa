import fs from "node:fs";
import { test } from "node:test";
import assert from "node:assert/strict";
import { generatePalette } from "../lib/theme/generate-palette.js";
import { buildThemeStyles } from "../lib/theme/apply-theme.js";

// The Steel UI trial pinned the colour to green, so a brand colour set in Central
// (blue on 20 Nov) never reached the panel while its name did.
test("the theme is built from the branding colour, not a fixed one", () => {
  const layout = fs.readFileSync(new URL("../app/layout.jsx", import.meta.url), "utf8");
  assert.match(layout, /generatePalette\(branding\.primary_color\)/);
  assert.doesNotMatch(layout, /STEEL_PRIMARY|#35B87A/i);
});

test("a blue brand colour gives a blue primary", () => {
  const css = buildThemeStyles(generatePalette("#1030d1"));
  const hue = Number(css.match(/--primary: oklch\([\d.]+ [\d.]+ ([\d.]+)\)/)[1]);
  assert.ok(Math.abs(hue - 265.3) < 1, `hue ${hue}`);
});

test("both themes use the same colour, as Central does", () => {
  const layout = fs.readFileSync(new URL("../app/layout.jsx", import.meta.url), "utf8");
  assert.match(layout, /if \(palette\) palette\.dark = palette\.light;/);
});
