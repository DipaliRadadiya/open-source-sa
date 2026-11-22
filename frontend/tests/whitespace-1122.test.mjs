import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];

// 22 Nov (Krishna): empty space a user would notice, page by page.
test("a short application log gets a short console", () => {
  const panel = read("components/applications/logs/application-logs-panel.jsx");
  assert.match(panel, /const SHORT_LOG = 15;/);
  assert.match(panel, /visible\.length <= SHORT_LOG/);
  assert.match(panel, /shortLog \? "\[&_\[role=log\]\]:min-h-48" : "h-\[calc\(100svh-16rem\)\]/);
});

test("once Central uses the key, the three setup steps fold into one line", () => {
  const panel = read("components/admin/central/central-panel.jsx");
  assert.match(panel, /\{linked \? \(\s*<p/);
  assert.match(panel, /t\("state\.lastUsedShort", \{ ago: lastUsedAgo \}\)/);
  for (const l of LOCALES) assert.match(JSON.parse(read(`messages/${l}.json`)).central.state.lastUsedShort, /\{ago\}/, l);
});

test("Activity Log reads When, User, What happened, then Type", () => {
  const table = read("components/activity-log/my-activity-table.jsx");
  assert.ok(table.indexOf('accessorKey: "description"') < table.indexOf('accessorKey: "type"'));
});

test("an application's Fail2ban page says 'not set up' once", () => {
  const page = read("app/(app)/applications/[application]/fail2ban/page.jsx");
  assert.doesNotMatch(page, /<Caution/);
  assert.match(page, /filterNote=\{status\.filterNote\}/);
  // The note renders only past the not-set-up early return.
  const panel = read("components/applications/fail2ban/fail2ban-panel.jsx");
  assert.ok(panel.indexOf("if (!config && !editing)") < panel.indexOf("{filterNote ? ("));
});

test("a backed-up application's summary does not repeat its schedule and storage", () => {
  const panel = read("components/applications/backups/backups-panel.jsx");
  assert.match(panel, /\{state === "protected" \? null : \(/);
  assert.doesNotMatch(panel, /t\("state\.protected\.body"/);
});
