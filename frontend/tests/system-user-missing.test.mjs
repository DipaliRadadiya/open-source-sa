import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { applicationSchema } from "../lib/schemas/application.js";

const LAYOUT = fs.readFileSync("app/(app)/applications/[application]/layout.jsx", "utf8");
const DIALOG = fs.readFileSync("components/applications/delete-application-dialog.jsx", "utf8");

test("a site without its system user still parses, paths and all", () => {
  // The API sends null for both paths when the user is gone; a required
  // string here would empty the list and the site page.
  const parsed = applicationSchema.safeParse({
    id: 7, name: "orphan", site_type: "wordpress", status: "active", system_user: null, document_root: null, path: null,
  });
  assert.equal(parsed.success, true, JSON.stringify(parsed.error?.issues));
  assert.equal(parsed.data.system_user, null, "null must survive: it is what tells the layout");
});

test("every screen of such a site becomes one panel with Delete", () => {
  // Strictly null: `undefined` means the user was not loaded, not that it is gone.
  assert.match(LAYOUT, /system_user === null/);
  assert.match(LAYOUT, /<SystemUserMissing/);
  assert.match(LAYOUT, /can\(permissions, "application", "manage"\)/, "same permission as the DELETE route");
});

test("the delete dialog does not offer to remove files it cannot find", () => {
  assert.match(DIALOG, /orphaned = application\?\.system_user === null/);
  assert.match(DIALOG, /removeFiles: removeFiles && !orphaned/);
  assert.match(DIALOG, /t\("filesKept"\)/);
});

test("reads under such a site are answered here, not sent to be refused", () => {
  // Next renders the hidden page next to the layout's panel; without this each
  // of its reads went to the API and came back 409.
  const READ = fs.readFileSync("lib/api/read.js", "utf8");
  assert.match(READ, /UNDER_APPLICATION = \/\^\\\/applications\\\/\(\\d\+\)\\\/\.\//, "the site itself must not match, or getApplication would recurse");
  assert.match(READ, /\(await getApplication\(site\[1\]\)\)\.application\?\.system_user === null/);
});
