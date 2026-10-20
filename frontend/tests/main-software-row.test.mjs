import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const card = fs.readFileSync(path.join(import.meta.dirname, "..", "components/dashboard/server-info-card.jsx"), "utf8");

// A "+1 more" tile took the slot the one hidden service could have used, next to
// an empty one (Krishna, 20 Oct).
test("extra software fills the row before a +N more tile appears", () => {
  assert.match(card, /const ROW_TILES = 5;/);
  assert.match(card, /lg:grid-cols-5/);
  assert.match(card, /const inline = extras\.length <= room \? extras : extras\.slice\(0, Math\.max\(0, room - 1\)\);/);
  assert.match(card, /const more = extras\.length - inline\.length;/);
});
