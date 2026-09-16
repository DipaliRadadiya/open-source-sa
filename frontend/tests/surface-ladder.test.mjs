import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Every surface must separate from the one it sits on.
 *
 * Tinting `--card` off white was shipped without checking what had been chosen
 * to contrast AGAINST a white card. `--secondary` sat 3.2% below white; the new
 * card landed 1.4% above it, and the "Not installed" badge stopped looking like
 * a badge. It was reported within minutes, and it was arithmetic — which means
 * it was checkable and I simply did not check it.
 *
 * The tint was then reverted: the card is white again. The check stays, and is
 * the more useful half of that episode — it is what catches the badge going
 * invisible if anyone moves `--card` or `--secondary` again, whichever
 * direction they move it in.
 *
 * So the ladder is asserted here. These tokens are authored as OKLCH lightness,
 * so the file is read directly: no browser, no server, no screenshot to squint
 * at. A step this small is exactly what an eye forgives in a screenshot and a
 * user does not on a real screen.
 */

const css = fs.readFileSync("app/globals.css", "utf8");

/** The OKLCH lightness a token is set to, inside one selector block. */
function lightness(block, token) {
  const match = block.match(new RegExp(`${token}:\\s*oklch\\(([\\d.]+)`));
  assert.ok(match, `${token} is not an oklch() value — this check cannot read it`);
  return Number(match[1]);
}

function blockFor(selector) {
  // Non-greedy to the first closing brace at line start, which is how every
  // block in this file is formatted.
  const match = css.match(new RegExp(`${selector}\\s*\\{([\\s\\S]*?)\\n\\}`));
  assert.ok(match, `no ${selector} block in globals.css`);
  return match[1];
}

// Below this two surfaces read as one. The pairing everyone agreed looked right
// — a white card with a 0.968 badge — was 0.032, so this leaves real headroom.
const MIN_STEP = 0.02;

for (const [name, selector] of [
  ["light", ":root"],
  ["dark", "\\.dark"],
]) {
  test(`${name}: every surface separates from the one it sits on`, () => {
    const block = blockFor(selector);

    // Dark declares --border as an alpha over the surface rather than a solid
    // lightness, so it is checked by eye/contrast elsewhere, not here.
    const solid = ["--background", "--card", "--secondary", "--muted", "--accent"];
    const L = Object.fromEntries(solid.map((token) => [token, lightness(block, token)]));

    /*
     * Badges, muted fills and accents sit ON a card and must separate from it.
     *
     * `--background` → `--card` is deliberately NOT here: in the light theme
     * they are both pure white, and the card is separated by its border and
     * shadow instead. Asserting a step there would fail the arrangement that
     * was actually chosen.
     */
    const pairs = [
      ["--card", "--secondary"],
      ["--card", "--muted"],
      ["--card", "--accent"],
    ];

    for (const [under, over] of pairs) {
      const step = Math.abs(L[under] - L[over]);
      assert.ok(
        step >= MIN_STEP,
        `${over} is ${step.toFixed(3)} from ${under} — under ${MIN_STEP}, so it reads as the same surface`,
      );
    }
  });
}

test("light theme: page and card are both plain white", () => {
  /*
   * Both ways of colouring this were built and rejected by the person who has
   * to look at it: a tinted page dulled every other screen, a tinted card
   * "not looks good". Pinning both to white keeps that from being re-litigated
   * by accident — and if it IS revisited deliberately, the ladder test above
   * is what stops the badge disappearing again.
   */
  const light = blockFor(":root");
  assert.equal(lightness(light, "--background"), 1, "the page background is tinted again");
  assert.equal(lightness(light, "--card"), 1, "the card surface is tinted again");
});
