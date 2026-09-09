import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const CARD = readFileSync(
  new URL("../components/php/extensions-card.jsx", import.meta.url),
  "utf8",
);
const EN = JSON.parse(readFileSync(new URL("../messages/en.json", import.meta.url), "utf8"));

test("a toggle in flight says which way it is going", () => {
  /*
   * There was already a spinner here, and it was reported as missing: a muted
   * 14px glyph tucked against the switch reads as part of the switch. The word
   * is what makes it an answer rather than a decoration — and "Enabling" and
   * "Disabling" are different answers, so the pending state has to carry the
   * direction, not just the name.
   */
  assert.match(CARD, /setPending\(\{ name: extension\.name, on: next \}\)/, "the pending state no longer records the direction");
  assert.match(CARD, /pending\.on \? t\("extensions\.enabling"\) : t\("extensions\.disabling"\)/);
  assert.equal(EN.php.extensions.enabling, "Enabling…");
  assert.equal(EN.php.extensions.disabling, "Disabling…");
});

test("the switch is held and announced while it runs", () => {
  // Disabled so a second click cannot race the first, and aria-busy so a screen
  // reader gets the same news the spinner gives everyone else.
  assert.match(CARD, /disabled=\{Boolean\(reason\) \|\| pending\?\.name === extension\.name\}/);
  assert.match(CARD, /aria-busy=\{pending\?\.name === extension\.name \|\| undefined\}/);
});

test("the label steps aside on a phone, the spinner does not", () => {
  /*
   * The status column is 96px below sm — measured at 390px, where the word does
   * not fit beside a switch. The spinner carries it alone there rather than
   * pushing the switch off the row, which is what a fixed-width column did to
   * this table once before.
   */
  assert.match(CARD, /hidden truncate sm:inline/, "the label no longer yields on a narrow column");
  assert.match(CARD, /<Loader2 className="size-4 shrink-0 animate-spin" aria-hidden \/>/);
});
