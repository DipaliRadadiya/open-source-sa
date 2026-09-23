import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

test("the extensions response keeps toggle_supported and each row's progress", async () => {
  const { phpExtensionsResponseSchema } = await import("../lib/schemas/php.js");
  const parsed = phpExtensionsResponseSchema.parse({
    extensions: [{ name: "imagick", installed: false, status: "failed", output: "E: Unable to locate package", current_step: null, reason: "install_failed", message: null, reference: "abc" }],
    panel_required: [],
    toggle_supported: false,
  });
  assert.equal(parsed.toggle_supported, false);
  // These were stripped, so the row's installing/failed line never showed.
  assert.equal(parsed.extensions[0].status, "failed");
  assert.equal(parsed.extensions[0].output, "E: Unable to locate package");
  // An API without the flag always allowed switching.
  assert.equal(phpExtensionsResponseSchema.parse({ extensions: [] }).toggle_supported, true);
});

test("on OpenLiteSpeed an installed extension has no switch, and Install still works", () => {
  const card = read("components/php/extensions-card.jsx");
  assert.match(card, /!toggleSupported && extension\.installed \? \(/);
  assert.match(card, /t\("extensions\.alwaysOn"\)/);
  assert.match(card, /t\("extensions\.install"\)/);
  assert.match(card, /t\("extensions\.noToggleNote"\)/);
  assert.match(read("app/(app)/php/page.jsx"), /toggleSupported=\{extensions\.toggle_supported\}/);
});
