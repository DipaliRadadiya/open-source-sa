import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

test("the list keeps ssh_access_enforced instead of stripping it", async () => {
  const { systemUsersResponseSchema } = await import("../lib/schemas/application.js");
  const meta = { current_page: 1, per_page: 10, total: 0, last_page: 1 };
  for (const value of [false, true, null]) {
    const parsed = systemUsersResponseSchema.parse({ system_users: [], meta: { ...meta, ssh_access_enforced: value } });
    assert.equal(parsed.meta.ssh_access_enforced, value);
  }
});

test("the SSH switches carry a warning while they keep nobody out", () => {
  const table = read("components/system-users/system-users-table.jsx");
  // Only a definite false: null means sshd could not be asked.
  assert.match(table, /meta\?\.ssh_access_enforced === false && data\.length \?/);
  assert.match(table, /t\("sshNotEnforced\.body"\)/);
  assert.match(table, /href="\/settings\/security" prefetch=\{false\}/);
  // The link only for someone who can save that screen.
  assert.match(read("app/(app)/system-users/page.jsx"), /canOpenSecurity=\{can\(permissions, "setting", "manage"\)\}/);
});

test("the shell picker takes the server's list as it comes, however long", () => {
  // The backend leaves out shells the server lacks (zsh on stock Ubuntu), so
  // the list can be 4 entries or 5. Nothing may assume a count or a path.
  for (const file of ["components/system-users/shell-select.jsx", "components/system-users/create-system-user-dialog.jsx"]) {
    const src = read(file);
    assert.doesNotMatch(src, /\/usr\/bin\/zsh|shells\[\d\]|shells\.length [=!]==? \d/, file);
    assert.match(src, /shells\.length\s*\?\s*shells/, `${file} renders what it was given`);
  }
});
