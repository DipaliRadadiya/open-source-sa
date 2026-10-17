import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const panel = fs.readFileSync(new URL("../components/admin/error-logs/error-log-panel.jsx", import.meta.url), "utf8");

test("the everyday search leads the toolbar; the reference lookup waits behind its own button", () => {
  // Two look-alike search boxes sat side by side, the rare one first (Krishna, 17 Oct).
  assert.ok(panel.indexOf("<LocalSearchInput") < panel.indexOf("<Popover "), "search before the reference button");
  assert.match(panel, /<PopoverContent[\s\S]*<ReferenceLookup[\s\S]*<\/PopoverContent>/);
  // While a reference is applied, the button says so.
  assert.match(panel, /reference \? t\("referenceActive", \{ reference: reference\.slice\(0, 8\) \}\) : t\("referenceFind"\)/);
  // "Last 100", not a bare number beside "Show".
  assert.match(panel, /t\("linesOption", \{ count: option \}\)/);
});
