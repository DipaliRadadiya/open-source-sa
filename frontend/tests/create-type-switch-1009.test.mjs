import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

// Krishna, 9 Oct: WordPress showed Moodle's `mdl_` table prefix after switching type.
const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("switching application type drops the old type's fields so the new one loads its defaults", () => {
  const src = read("components/applications/create-application-form.jsx");
  assert.match(src, /function dropTypeFields\(next\) \{\s*if \(next === form\.getValues\("site_type"\)\) return;\s*for \(const config of typeFields\) form\.unregister\(config\.name\);/);
  assert.match(src, /onChange=\{\(next\) => \{\s*dropTypeFields\(next\);\s*field\.onChange\(next\);/);
  // Remounted per type, so a field of the same name registers again with its own default.
  assert.equal(src.match(/key=\{`\$\{selectedName\}:\$\{config\.name\}`\}/g)?.length, 2);
});
