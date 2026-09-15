import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { SITE_TYPE_LOGOS, siteTypeLogo } from "../lib/applications/site-type-logo.js";

const COMPONENT = fs.readFileSync("components/applications/site-type-logo.jsx", "utf8");

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

test("the logo sits in a fixed-width slot, so the names beside it line up", () => {
  /*
   * At `w-auto` each logo is as wide as its own aspect ratio makes it — Craft's
   * square is 28px, Moodle's wordmark 48 — so the name in the next column
   * started up to 20px further right on one row than the next. Measured at 1440
   * across twelve types: twelve different starts before, one after.
   *
   * The slot is what fixes it, so the image must never carry a width of its
   * own again: `max-w-full` inside a sized `span`, not `w-auto max-w-12` on the
   * `<img>`.
   */
  assert.match(COMPONENT, /size = "h-7 w-12"/, "the default slot has a fixed width");
  assert.match(COMPONENT, /className="max-h-full max-w-full object-contain"/);
  assert.doesNotMatch(COMPONENT, /<img[^>]*w-auto/s, "a width on the image defeats the slot");
  assert.match(COMPONENT, /items-center justify-center/, "the logo is centred in the slot");
});

test("the fallback tile occupies the same column as a logo", () => {
  /*
   * A type with no artwork is rare but not impossible — anything added
   * upstream before we ship its file. If its tile sat outside the slot, that
   * one row's name would be the only one out of line, which reads as a bug in
   * the row rather than as a missing logo.
   */
  assert.match(COMPONENT, /aspect-square h-full/);
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

test("the picker's category chips are buckets, not the API's own categories", async () => {
  /*
   * The catalogue carries 11 categories for 17 types and 8 of them hold
   * exactly one: a row of eleven tabs where "education" opens on Moodle by
   * itself. The chips fold those into four a person would reach for, and a
   * category nobody has placed lands in `others` rather than vanishing from
   * the grid.
   */
  const { CATEGORY_GROUPS, groupForType, groupsWithTypes } = await import(
    "../lib/applications/type-categories.js"
  );

  assert.equal(groupForType({ category: "cms" }), "cms");
  assert.equal(groupForType({ category: "ecommerce" }), "cms");
  assert.equal(groupForType({ category: "monitoring" }), "tools");
  assert.equal(groupForType({ category: "CMS" }), "cms", "the API's casing is not load-bearing");
  assert.equal(groupForType({ category: "quantum-computing" }), "others");
  assert.equal(groupForType({}), "others");

  // No category is claimed by two buckets — the first match would win in
  // silence and one chip would be permanently short.
  const seen = new Set();
  for (const group of CATEGORY_GROUPS) {
    for (const category of group.categories) {
      assert.equal(seen.has(category), false, `${category} is in two groups`);
      seen.add(category);
    }
  }

  // An empty bucket is not offered: a chip that filters to nothing is a
  // promise the panel cannot keep.
  const groups = groupsWithTypes([{ category: "cms" }, { category: "cms" }, { category: "developer" }]);
  assert.deepEqual(
    groups.map((g) => [g.key, g.count]),
    [["cms", 2], ["development", 1]],
  );

  // `others` is last when it exists at all — it is where the unknown goes,
  // not a peer of the buckets we chose.
  const withUnknown = groupsWithTypes([{ category: "cms" }, { category: "nothing-we-know" }]);
  assert.equal(withUnknown.at(-1).key, "others");
});
