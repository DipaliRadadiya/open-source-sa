import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

import { SIDEBAR_GROUPS_COOKIE, parseSidebarGroups } from "../lib/navigation.js";

const read = (p) => fs.readFileSync(p, "utf8");

// Reported 28 Oct: Software, shut by default, closed again after "Restart server" (the restart screen reloads).
test("a stored choice survives only as booleans; anything else is ignored", () => {
  assert.deepEqual(parseSidebarGroups(JSON.stringify({ "server:software": true, "server:data": false })), { "server:software": true, "server:data": false });
  assert.deepEqual(parseSidebarGroups(JSON.stringify({ "server:software": "yes", ["x".repeat(65)]: true })), {});
  for (const bad of [undefined, "", "not json", "[true]", "null", "3"]) assert.deepEqual(parseSidebarGroups(bad), {});
});

test("the sidebar starts from the cookie the layout read, and writes every toggle back", () => {
  const layout = read("app/(app)/layout.jsx");
  assert.match(layout, /<AppSidebar items=\{permissions\} initialGroups=\{parseSidebarGroups\(\(await cookies\(\)\)\.get\(SIDEBAR_GROUPS_COOKIE\)\?\.value\)\} \/>/);
  const sidebar = read("components/sections/app-sidebar.jsx");
  assert.match(sidebar, /const \[toggled, setToggled\] = useState\(initialGroups\);/);
  assert.match(sidebar, /document\.cookie = `\$\{SIDEBAR_GROUPS_COOKIE\}=\$\{encodeURIComponent\(JSON\.stringify\(next\)\)\}; path=\/; max-age=31536000; samesite=lax`;/);
  // Per panel: the server and an application both have a "security" group.
  assert.match(sidebar, /toggled\[`\$\{currentPanel\}:\$\{group\.key\}`\]/);
  assert.match(sidebar, /onClick=\{\(\) => toggle\(`\$\{currentPanel\}:\$\{group\.key\}`, !open\)\}/);
  assert.equal(SIDEBAR_GROUPS_COOKIE, "sidebar_groups");
});
