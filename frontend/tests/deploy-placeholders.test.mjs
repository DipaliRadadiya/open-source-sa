import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { applicationSchema } from "../lib/schemas/application.js";

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

test("{path} maps to the document root, matching the backend", () => {
  // GitDeployer::expand() substitutes the document root for `{path}` — not
  // `path`, which is its parent for a fixed-web-root type. Showing one and
  // running the other would be worse than showing nothing.
  const card = readFileSync(
    new URL("../components/applications/deployment/deploy-settings-card.jsx", import.meta.url),
    "utf8",
  );
  assert.match(card, /"\{path\}": application\?\.document_root/);
});
