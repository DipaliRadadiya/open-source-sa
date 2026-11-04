import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

// Click-through QA, 4 Nov.
const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), "utf8");

test("a generated system user password is shown with a copy button, and hidden again when typed over", () => {
  const form = read("components/applications/create-application-form.jsx");
  const block = form.slice(form.indexOf('name="system_user_password"'), form.indexOf('name="system_user_password"') + 4000);
  assert.match(block, /show=\{revealUserPassword\}/);
  assert.match(block, /setRevealUserPassword\(true\);\s*userPasswordGenerated\.current = true;/);
  assert.match(block, /if \(userPasswordGenerated\.current\) \{\s*userPasswordGenerated\.current = false;\s*setRevealUserPassword\(false\);/);
  assert.match(block, /<CopyButton value=\{String\(field\.value\)\}/);
});

test("a destination's card shows the automatic test's verdict without a reload", () => {
  // It kept "Never tested" after adding, and the old credentials' verdict after replacing them.
  for (const file of ["components/integrations/storage/connect-dialog.jsx", "components/integrations/storage/replace-credentials-dialog.jsx"]) {
    const src = read(file);
    const after = src.slice(src.indexOf("await probeDestination("));
    assert.match(after, /if \(!verdict\.notRun\) await refreshAndWait\(\);/, file);
  }
});
