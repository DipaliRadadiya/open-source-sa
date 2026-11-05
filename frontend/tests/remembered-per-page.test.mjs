import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

// Krishna, 5 Nov: picking 20 per page on one list should hold on every list.
const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("the select saves the choice and shows the size the list was served with", () => {
  const select = read("components/data-table/per-page-select.jsx");
  assert.match(select, /savePerPage\(next\);\s*apply\(next\);/);
  assert.match(select, /: served\);/);
  assert.match(read("components/data-table/data-table-pagination.jsx"), /<PerPageSelect label=\{t\("perPage"\)\} fallback=\{meta\.per_page\} \/>/);
  assert.match(read("components/firewall/history-dialog.jsx"), /const remembered = readPerPage\(\);\s*setPerPage\(remembered\);\s*load\(1, remembered\);/);
});

test("every paginated list falls back to the remembered size, not a fixed 10", () => {
  for (const p of ["lib/roles/get-roles.js", "lib/system-users/get-system-users.js", "lib/applications/get-applications.js", "lib/databases/get-databases.js", "lib/firewall/get-firewall.js"]) {
    assert.match(read(p), /defaultPerPage: await rememberedPerPage\(\)/, p);
  }
  for (const p of ["lib/account/get-my-activity.js", "lib/cron-jobs/get-cronjobs.js", "lib/activity-log/get-server-activity.js", "lib/activity-log/get-activity-log.js", "lib/users/get-users.js"]) {
    assert.match(read(p), /const perPage = await perPageFrom\(searchParams\.per_page\);/, p);
  }
  assert.match(read("lib/backups/get-backups.js"), /per_page: await perPage\(searchParams\.per_page\)/);
  assert.match(read("app/(app)/disk-cleaner/page.jsx"), /const perPage = await perPageFrom\(query\?\.per_page\);/);
  // Fixed sizes chosen by the code itself stay fixed.
  assert.match(read("lib/dashboard/get-overview.js"), /per_page: 10/);
});

test("the cookie is read and written under one name, only for allowed sizes", async () => {
  const { PER_PAGE_COOKIE, LIST_PER_PAGE_OPTIONS } = await import("../lib/schemas/list.js");
  assert.equal(PER_PAGE_COOKIE, "list_per_page");
  assert.deepEqual(LIST_PER_PAGE_OPTIONS, [10, 20, 50, 100]);
  const cookie = read("lib/tables/per-page-cookie.js");
  assert.match(cookie, /\$\{PER_PAGE_COOKIE\}=\$\{Number\(value\)\}; path=\/; max-age=31536000; samesite=lax/);
  assert.match(cookie, /if \(!LIST_PER_PAGE_OPTIONS\.includes\(Number\(value\)\)\) return;/);
});
