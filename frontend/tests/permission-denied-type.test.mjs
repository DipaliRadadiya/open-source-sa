import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

// Krishna, 5 Nov: an admin opening PHP Settings on a Node app by URL was told "You don't have access".
const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
const denied = read("components/sections/permission-denied.jsx");

test("admins see 'not used for this application' on a per-type screen", () => {
  assert.match(denied, /typeFeature && \(await getCurrentUser\(\)\.catch\(\(\) => null\)\)\?\.is_admin/);
  assert.match(denied, /t\("notForTypeTitle"\)/);
  for (const page of ["php", "container", "compose"]) {
    const src = read(`app/(app)/applications/[application]/${page}/page.jsx`);
    assert.equal((src.match(/typeFeature/g) ?? []).length, 1, page);
    // The server-level check stays a plain permission refusal.
    assert.doesNotMatch(src, /can\(permissions, "application", "view"\)\)\s*return <PermissionDenied[^>]*typeFeature/, page);
  }
});

test("the strings exist in every locale", () => {
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const pd = JSON.parse(read(`messages/${l}.json`)).common.permissionDenied;
    assert.ok(pd.notForTypeTitle && pd.notForTypeDescription, l);
  }
});
