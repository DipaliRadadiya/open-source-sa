import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { phpGroupSchema } from "../lib/schemas/php.js";

// GET /php gained `full_version` ("8.4.14") per row and `default_full_version`
// (backend c4ce6a71a). Zod strips unknown keys, so without the schema fields
// the PHP page would never see them.

test("the schema keeps full_version and default_full_version", () => {
  const parsed = phpGroupSchema.parse({
    default: "8.4",
    default_full_version: "8.4.14",
    versions: [
      { version: "8.4", full_version: "8.4.14", status: "ready" },
      { version: "8.3", full_version: null, status: "installing" },
    ],
  });
  assert.equal(parsed.default_full_version, "8.4.14");
  assert.equal(parsed.versions[0].full_version, "8.4.14");
  assert.equal(parsed.versions[1].full_version, null);
});

test("an older API without the fields still parses", () => {
  const parsed = phpGroupSchema.parse({ default: "8.4", versions: [{ version: "8.4" }] });
  assert.equal(parsed.versions[0].full_version, undefined);
});

test("the version row shows the exact release and falls back to the minor line", () => {
  const src = fs.readFileSync("components/php/version-summary.jsx", "utf8");
  assert.match(src, /t\("versions\.name", \{ version: version\.full_version \?\? version\.version \}\)/);
  // Actions keep the minor line: it is the identifier the API routes on.
  assert.match(src, /installPhpVersion\(version\.version\)/);
  assert.match(src, /removePhpVersion\(version\.version\)/);
});

// Krishna, 5 Nov: the server PHP card "looks like documentation". Explanations sit behind
// the ⓘ, as on the application PHP page; only the upload warning stays on the page.
test("server PHP common settings keep explanations behind the info icon", () => {
  const src = fs.readFileSync("components/php/php-common-settings.jsx", "utf8");
  assert.match(src, /<LabelHint>\{explain\}<\/LabelHint>/);
  assert.doesNotMatch(src, /hint=\{tApp\("hints\./);
  assert.match(src, /hint=\{raisesPost \? t\("uploadRaisesPost", \{ size: upload \}\) : null\}/);
});
