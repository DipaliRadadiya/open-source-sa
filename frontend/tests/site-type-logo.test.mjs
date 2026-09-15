import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { SITE_TYPE_LOGOS, siteTypeLogo } from "../lib/applications/site-type-logo.js";

test("every mapped logo file is actually on disk", () => {
  /*
   * The failure this exists for is silent: a missing file renders as a broken
   * image, not as the fallback icon, so the picker looks broken rather than
   * plain. Two of the names do not match their file (`craftcms` → craft.svg,
   * `static` → selfhosted.png), which is exactly where a typo would hide.
   */
  for (const [name, file] of Object.entries(SITE_TYPE_LOGOS)) {
    assert.equal(
      fs.existsSync(`public/site-types/${file}`),
      true,
      `${name} points at public/site-types/${file}, which does not exist`,
    );
  }
});

test("a type we have no logo for falls back rather than guessing a path", () => {
  /*
   * Deriving the path from the name would return "/site-types/ghost.svg" for a
   * type added upstream tomorrow — a broken image on the create screen. Null
   * is what lets the caller draw the category glyph instead.
   */
  assert.equal(siteTypeLogo("ghost"), null);
  assert.equal(siteTypeLogo(""), null);
  assert.equal(siteTypeLogo(undefined), null);
});

test("the names that differ from their file are mapped, not transformed", () => {
  assert.equal(siteTypeLogo("craftcms"), "/site-types/craft.svg");
  assert.equal(siteTypeLogo("uptimekuma"), "/site-types/uptime-kuma.svg");
  assert.equal(siteTypeLogo("nodered"), "/site-types/node-red.svg");
});

test("a static site gets HTML5, not a product logo", () => {
  /*
   * It was pointed at the supplied set's "Reseller Panel" artwork — a
   * different product entirely, sitting on the row for plain HTML. A static
   * site has no brand, so it shows the thing it is made of.
   */
  assert.equal(siteTypeLogo("static"), "/site-types/html5.svg");
});

test("no logo file is left unused", () => {
  /*
   * An orphan means a rename happened on one side only — the file is shipped,
   * nothing points at it, and the type it was for is drawing a grey glyph.
   */
  const mapped = new Set(Object.values(SITE_TYPE_LOGOS));
  const onDisk = fs.readdirSync("public/site-types");
  const orphans = onDisk.filter((f) => !mapped.has(f));
  assert.deepEqual(orphans, [], `unused logo files: ${orphans.join(", ")}`);
});
