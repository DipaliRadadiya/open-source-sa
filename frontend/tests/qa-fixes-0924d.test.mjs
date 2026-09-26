import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (p) => fs.readFileSync(p, "utf8");

test("an off state is a filled grey pill, not bare text", () => {
  // From the saved state since PP-A (2026-09-25); off is still the grey pill.
  assert.match(read("components/applications/security/security-section.jsx"), /variant=\{alreadyProtected \? "success" : "muted"\}/);
  // From the saved state since WAF-A (2026-09-26); off is still the grey pill.
  assert.match(read("components/applications/firewall/firewall-section.jsx"), /blocking \? "success" : live\.enabled \? "warning" : "muted"/);
  assert.match(read("components/services/service-status-badge.jsx"), /inactive: \{ icon: CircleMinus, variant: "muted" \}/);
  assert.match(read("lib/activity-log/labels.js"), /if \(!action\) return "muted";/);
});

test("the clone form leaves card spacing between its last field and the footer", () => {
  assert.match(read("components/applications/clone/clone-panel.jsx"), /className="flex flex-col gap-\(--card-spacing\)"/);
});

test("deleting an application ticks files and databases by default, and resets to ticked", () => {
  const src = read("components/applications/delete-application-dialog.jsx");
  assert.match(src, /useState\(true\);\n\s+\/\/ Null when the user is gone/);
  assert.match(src, /const \[removeDatabases, setRemoveDatabases\] = useState\(true\)/);
  assert.match(src, /setRemoveFiles\(true\);\n\s+setRemoveDatabases\(true\);/);
  // An orphaned site still never sends remove_files.
  assert.match(src, /removeFiles: removeFiles && !orphaned/);
});

test("a review link focuses the field's own control before any label-row button", () => {
  const src = read("components/applications/create-application-form.jsx");
  assert.doesNotMatch(src, /'\[data-slot="form-control"\], input, textarea, button:not\(\[disabled\]\)'/);
  assert.match(src, /\.map\(\(selector\) => container\?\.querySelector\(selector\)\)\s*\.find\(Boolean\)/);
});

test("deleting a system user keeps the page it was on", () => {
  const src = read("components/system-users/delete-system-user-dialog.jsx");
  assert.doesNotMatch(src, /router\.push\("\/system-users"\)/);
  assert.match(read("app/(app)/system-users/page.jsx"), /redirectOutOfRange\("\/system-users"/);
});

test("firewall history keeps its per-page selector while the history is longer than 10", () => {
  const src = read("components/firewall/history-dialog.jsx");
  assert.match(src, /state\.meta\?\.last_page > 1 \|\| state\.meta\?\.total > PER_PAGE_OPTIONS\[0\]/);
  assert.match(src, /\{state\.meta\.last_page > 1 \? \(\s*<div className="self-end">/);
});

test("a staging copy can be deleted from its production's Staging page", () => {
  const panel = read("components/applications/staging/staging-panel.jsx");
  assert.match(panel, /<DeleteApplicationDialog application=\{staging\} open=\{removing\} onOpenChange=\{setRemoving\} \/>/);
  assert.match(panel, /disabled=\{!canDelete\}/);
  // Gated like the API gates it: an application delete, not app_staging.
  assert.match(read("app/(app)/applications/[application]/staging/page.jsx"), /const canDelete = can\(permissions, "application", "manage"\);/);
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const m = JSON.parse(read(`messages/${l}.json`)).applications.staging.remove;
    assert.ok(m.title && m.action && m.body.includes("{domain}") && m.body.includes("{production}"), l);
  }
});
