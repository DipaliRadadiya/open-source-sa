import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { countAccess, accessCounts } from "../lib/roles/granted-count.js";
import { ACCESS_MANAGE, ACCESS_NONE, ACCESS_VIEW } from "../lib/schemas/role.js";

test("view and manage are counted apart; manage implies view", () => {
  assert.deepEqual(countAccess([ACCESS_VIEW, ACCESS_MANAGE, ACCESS_NONE, undefined]), { view: 2, manage: 1 });
  assert.deepEqual(countAccess([]), { view: 0, manage: 0 });
});

test("a view-only role no longer reads like Administrator", () => {
  const viewOnly = { permissions: Array.from({ length: 38 }, () => ({ access: ACCESS_VIEW })) };
  const admin = { permissions: Array.from({ length: 38 }, () => ({ access: ACCESS_MANAGE })) };
  assert.deepEqual(accessCounts(viewOnly), { view: 38, manage: 0 });
  assert.deepEqual(accessCounts(admin), { view: 38, manage: 38 });
  // An older backend sends the boolean pair instead of `access`.
  assert.deepEqual(accessCounts({ permissions: [{ permissions: { view: true, manage: true } }, { permissions: { view: true, manage: false } }] }), { view: 2, manage: 1 });
});

test("the role editor folds its sections and keeps Save on screen", () => {
  const matrix = fs.readFileSync(new URL("../components/admin/roles/permission-matrix.jsx", import.meta.url), "utf8");
  assert.match(matrix, /<Collapsible key=\{sectionKey\}/);
  assert.match(matrix, /t\("groupCount", \{ \.\.\.countAccess\(items\.map\(accessFor\)\), total: items\.length \}\)/);
  const form = fs.readFileSync(new URL("../components/admin/roles/role-form.jsx", import.meta.url), "utf8");
  assert.match(form, /className="sticky bottom-0/);
});
