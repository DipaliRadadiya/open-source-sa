import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { ionCubeSchema } from "../lib/schemas/php.js";
import { anyInFlight, isInFlight } from "../lib/runtime/in-flight.js";

const CARD = fs.readFileSync("components/php/ioncube-card.jsx", "utf8");
const PAGE = fs.readFileSync("app/(app)/php/page.jsx", "utf8");

test("an unsupported PHP version is a normal answer, not a failure", () => {
  /*
   * ionCube publishes no loader for PHP 8.0 and the panel still offers 8.0.
   * The backend refuses the install with 422 before it creates a tracker row,
   * so the card has to know from `supported` and never offer the button —
   * otherwise the only way to learn is to press it and be told off.
   */
  const parsed = ionCubeSchema.parse({
    supported: false,
    installed: false,
    php_version: "8.0",
    loader_version: null,
  });
  assert.equal(parsed.supported, false);
  assert.match(CARD, /\{supported \? \(/, "the action is rendered only when supported");
  assert.match(CARD, /!supported \? \(/, "and the reason is shown when it is not");
});

test("the card reads the install state through the shared tracker vocabulary", () => {
  /*
   * The backend reuses `installing | ready | failed` here, which is what lets
   * one poll cover versions, extensions and this. A private set of state names
   * would have needed a second timer on the same page.
   */
  assert.equal(isInFlight("installing"), true);
  assert.equal(isInFlight("failed"), false, "failed is settled — polling it would never stop");
  assert.equal(isInFlight("idle"), false, "idle means nothing has ever run");
  assert.equal(anyInFlight([{ status: "installing" }]), true);
});

test("the page polls while ionCube is installing", () => {
  /*
   * The install is queued behind a ~29MB download and finishes without telling
   * anyone. Without this the card sits on "Installing" until you navigate away
   * and back — which is what made a finished install look stuck for the
   * version list before it was fixed the same way.
   */
  assert.match(PAGE, /anyInFlight\(\[ioncube\]\)/);
});

test("neither per-version endpoint is called while the version is not ready", () => {
  /*
   * Both 404 on a version that is still installing or failed. Asking anyway
   * spends a request to be told what the version list already said.
   */
  assert.match(PAGE, /installState\s*\?\s*\[\{ data: null \}/);
});

test("a status we cannot read still renders the card, saying so", () => {
  /*
   * Returning nothing would read as "this panel has no ionCube support",
   * which is a different and wrong answer from "we could not ask right now".
   */
  assert.match(CARD, /failed \|\| !ioncube/);
  assert.match(CARD, /loadFailed/);
});

test("the schema tolerates the fields the API omits", () => {
  // `loader_version`, `sha256` and `path` are all null until it is installed,
  // and `reason`/`reference` only exist after a failure.
  const parsed = ionCubeSchema.parse({ supported: true, installed: false });
  assert.equal(parsed.installed, false);
  assert.equal(parsed.loader_version ?? null, null);
});
