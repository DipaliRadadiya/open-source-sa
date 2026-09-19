import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { engineSchema } from "../lib/schemas/database.js";

/*
 * Krishna: "why are we showing mongo db engine installation on ols 26?"
 *
 * MongoDB has published no server build for Ubuntu 26.04 (resolute) — it ships
 * the tools and not the server. The BACKEND knew: `canInstall()` already
 * returns false, and every engine carries an `unavailable: {code, reason}` with
 * a sentence naming the release.
 *
 * The frontend threw it away. `engineSchema` did not declare the field and Zod
 * strips what is not declared, so the card fell back to the generic "Has to be
 * installed manually — it isn't in the server's package list". That is not
 * true and it is actionable-sounding: it sends somebody off to install by hand
 * something that does not exist for their release.
 */

test("the reason an engine cannot be installed survives parsing", () => {
  const parsed = engineSchema.parse({
    engine: "mongodb",
    installable: false,
    unavailable: {
      code: "os_unsupported",
      reason: "MongoDB has not published packages for Ubuntu 26.04 yet.",
    },
  });

  assert.equal(parsed.unavailable?.code, "os_unsupported");
  assert.match(parsed.unavailable?.reason, /26\.04/);
});

test("an engine that can be installed carries no reason", () => {
  const parsed = engineSchema.parse({ engine: "mariadb", installable: true });
  assert.equal(parsed.unavailable ?? null, null);
});

test("the card prefers the server's sentence over the generic one", () => {
  // Ours only knows apt came back empty. The API knows why, and "install it
  // manually" is advice that cannot be followed for this failure.
  const card = fs.readFileSync("components/databases/engine-state.jsx", "utf8");
  const code = card.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\{\/\*[\s\S]*?\*\/\}/g, "");
  assert.match(code, /engine\.unavailable\?\.reason \?\? t\("install\.notInstallable"\)/);
});

test("the create form does not offer to install an engine it cannot install", () => {
  /*
   * Krishna, having seen the Databases page fixed: "what about in application
   * creation form where if supported things not installed we show there option
   * to install".
   *
   * NodeBB needs MongoDB. On Ubuntu 26.04 the panel cannot install MongoDB at
   * all, so that row offered an Install button whose only outcome is a 422 —
   * the exact failure this screen exists to prevent.
   *
   * The runtime branch had already drawn the line ("Offering a button there
   * would be a button that cannot work") and used `impossible` for it. The
   * database branch never checked. Same state, same word now.
   */
  const services = fs.readFileSync("components/applications/required-services.jsx", "utf8");
  const code = services.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\{\/\*[\s\S]*?\*\/\}/g, "");

  assert.match(code, /engine\.installable === false && !engine\.installed\) return "impossible"/);

  // Only `missing` and `failed` are queued, so an impossible row cannot be
  // swept up by Install all either.
  assert.match(code, /service\.state === "missing" \|\| service\.state === "failed"/);
});

test("the reason is on the row, not only in a tooltip", () => {
  /*
   * Krishna, twice: "user will ignore it and not read it", then "show proper
   * message why not available". A tooltip does not open on a touch screen and
   * nobody hovers a badge they have already read as a label, so the one
   * sentence explaining a dead end was effectively unpublished.
   *
   * Only for this state: a row that is merely missing has a button and needs
   * no paragraph.
   */
  const panel = fs.readFileSync("components/applications/required-services-panel.jsx", "utf8");
  const code = panel.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\{\/\*[\s\S]*?\*\/\}/g, "");

  assert.match(code, /\{service\.reason \? \(/, "the row must render the reason itself");
  assert.match(code, /<span>\{service\.reason\}<\/span>/);
  assert.match(code, /border-destructive\/30 bg-destructive\/5/, "as a notice, not body text");
});

test("the badge says why, in the server's words", () => {
  // The generic sentence is about version ranges — true of PrestaShop and PHP,
  // and useless for an engine the vendor has not shipped for this release.
  const services = fs.readFileSync("components/applications/required-services.jsx", "utf8");
  assert.match(services, /unavailable\?\.reason/);

  const panel = fs.readFileSync("components/applications/required-services-panel.jsx", "utf8");
  assert.match(panel, /reason=\{service\.reason \?\? t\("state\.impossibleReason"\)\}/);
});
