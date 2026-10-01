import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { applicationSchema } from "../lib/schemas/application.js";
import { deploySettingsSchema } from "../lib/schemas/deploy-history.js";

test("the deploy script's {path} has a value to show", () => {
  /*
   * Reported as "{path} is blank while {branch} and {domain} are fine". The
   * API sends `document_root`; the schema did not declare it, so Zod dropped
   * it and the card read undefined. `path` was declared, `document_root` was
   * not, and the token expands to the latter.
   */
  const parsed = applicationSchema.parse({
    id: 1, name: "x", domain: "x.example.com", site_type: "git", status: "active", settings: {},
    document_root: "/home/deploy/site/public_html/web",
    path: "/home/deploy/site/public_html",
  });
  assert.equal(parsed.document_root, "/home/deploy/site/public_html/web");
  assert.equal(parsed.path, "/home/deploy/site/public_html");
});

test("each variable shows what the deploy really substitutes, from the server", () => {
  // The client used to guess: `document_root` for {path} (the deploy runs in the code
  // root, which differs for sites with a web-root subfolder) and "PHP 8.4" for {php}.
  // GitDeployer::placeholderValues() is the same code the deploy uses.
  const card = readFileSync(
    new URL("../components/applications/deployment/deploy-settings-card.jsx", import.meta.url),
    "utf8",
  );
  assert.match(card, /\.\.\.settings\.placeholder_values,/);
  assert.doesNotMatch(card, /application\?\.document_root|`PHP \$\{application\.php_version\}`/);
  const values = { "{path}": "/home/u/shop/public_html", "{php}": "/usr/bin/php8.4", "{PHP83}": "/usr/bin/php8.3" };
  assert.deepEqual(deploySettingsSchema.parse({ placeholder_values: values }).placeholder_values, values);
  // PHP sends an empty map as [].
  assert.deepEqual(deploySettingsSchema.parse({ placeholder_values: [] }).placeholder_values, {});
});
