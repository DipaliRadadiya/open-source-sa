import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

// Krishna, 5 Nov: the "Used by" popover on PHP/Node listed names as wrapped grey chips
// that all looked alike. Now one readable row per application, each opening its search.
test("used-by popover lists one application per row with a link", () => {
  const src = fs.readFileSync("components/runtime/versions-table.jsx", "utf8");
  assert.match(src, /href=\{`\/applications\?search=\$\{encodeURIComponent\(site\)\}`\}/);
  assert.match(src, /prefetch=\{false\}/);
  assert.match(src, /<span className="min-w-0 flex-1 text-sm \[overflow-wrap:anywhere\]">\{site\}<\/span>/);
  assert.doesNotMatch(src, /rounded-md bg-muted px-2 py-0\.5 text-xs/);
});
