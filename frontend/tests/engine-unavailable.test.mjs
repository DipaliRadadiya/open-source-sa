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
