import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { placeTarget } from "../lib/files/path-helpers.js";

test("Extract's unedited default for an archive one folder deep is that folder, not the folder inside itself", () => {
  // Found on the test panel: wp-content/a.zip → "wp-content / wp-content" → "folder doesn't exist".
  assert.equal(placeTarget("wp-content", "wp-content/a.zip", "wp-content"), "wp-content");
  assert.equal(placeTarget("", "a.zip", ""), "");
  assert.equal(placeTarget("wp-content/plugins", "wp-content/plugins/a.zip", "wp-content/plugins"), "wp-content/plugins");
});

test("a bare name the user types still stays in the item's folder", () => {
  assert.equal(placeTarget("plugins", "wp-content/a.zip", "wp-content"), "wp-content/plugins");
  assert.equal(placeTarget("b.txt", "qa/sub/a.txt", "qa/sub/a.txt"), "qa/sub/b.txt");
  assert.equal(placeTarget("/top", "wp-content/a.zip", "wp-content"), "top");
  assert.equal(placeTarget("x/y", "wp-content/a.zip", "wp-content"), "x/y");
});

test("the target dialog reads the field through placeTarget", () => {
  assert.match(fs.readFileSync("components/applications/files/target-path-dialog.jsx", "utf8"), /placeTarget\(typed, file\.path, defaultTarget\)/);
});
