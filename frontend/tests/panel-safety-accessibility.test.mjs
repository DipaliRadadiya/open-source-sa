import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), "utf8");
}

const unsavedGuard = read("components/ui/unsaved-guard.jsx");
const appSidebar = read("components/sections/app-sidebar.jsx");
const adminSidebar = read("components/sections/admin-sidebar.jsx");
const settingsTabs = read("components/settings/settings-tabs.jsx");
const roleForm = read("components/admin/roles/role-form.jsx");
const panelFocus = read("components/sections/panel-focus.jsx");
const appLayout = read("app/(app)/layout.jsx");
const adminLayout = read("app/admin/layout.jsx");
const statCards = read("components/dashboard/stat-cards.jsx");
const liveMetrics = read("components/dashboard/live-metrics-section.jsx");

test("unsaved work uses one panel-wide confirmation", () => {
  assert.match(unsavedGuard, /const \[pendingAction, setPendingAction\]/);
  assert.match(unsavedGuard, /const guardAction = useCallback/);
  assert.match(unsavedGuard, /const guardNavigation = useCallback/);
  assert.match(unsavedGuard, /<ConfirmDialog/);
  assert.match(unsavedGuard, /title=\{t\("unsavedTitle"\)\}/);

  assert.match(appSidebar, /guardNavigation\(item\.href/);
  assert.doesNotMatch(appSidebar, /leavingTo|<ConfirmDialog/);
  assert.match(settingsTabs, /guardNavigation\(href\)/);
  assert.doesNotMatch(settingsTabs, /leavingTo|<ConfirmDialog/);
});

test("Admin role edits register with the shared navigation guard", () => {
  assert.match(adminLayout, /<UnsavedProvider>/);
  assert.match(roleForm, /useWatchUnsaved\("admin-role", isDirty\)/);
  assert.match(adminSidebar, /guardNavigation\(item\.url/);
});

test("all panel escape routes consult the unsaved guard", () => {
  const navigationFiles = [
    "components/sections/app-breadcrumb.jsx",
    "components/sections/admin-breadcrumb.jsx",
    "components/sections/app-header.jsx",
    "components/sections/admin-header.jsx",
  ];
  for (const file of navigationFiles) {
    assert.match(read(file), /guardNavigation\(/, file);
  }

  const userMenu = read("components/sections/user-menu.jsx");
  assert.match(userMenu, /guardAction\(performLogout\)/);
  assert.match(userMenu, /guardAction\(performBackToAccount\)/);
});

test("both panel shells expose a skip target and focus new route headings", () => {
  assert.match(panelFocus, /href="#main-content"/);
  assert.match(panelFocus, /const pathname = usePathname\(\)/);
  assert.match(panelFocus, /if \(initial\.current\)/);
  assert.match(panelFocus, /document\.querySelector\("#main-content h1"\)/);
  assert.match(panelFocus, /heading\.focus\(\{ preventScroll: true \}\)/);

  for (const layout of [appLayout, adminLayout]) {
    assert.match(layout, /<PanelFocus \/>/);
    assert.match(layout, /<main id="main-content" tabIndex=\{-1\}/);
  }
});

test("dashboard polling announces connection transitions, not every metric", () => {
  assert.doesNotMatch(statCards, /aria-live=/);
  assert.match(statCards, /aria-busy=\{loading\}/);
  assert.doesNotMatch(liveMetrics, /<div role="status"/);
  assert.match(liveMetrics, /previous\.current === failed/);
  assert.match(liveMetrics, /aria-live="polite"/);
  assert.match(liveMetrics, /t\("recovered"\)/);
});

test("new accessibility copy exists in every active locale", () => {
  for (const locale of ["en", "es", "hi"]) {
    const messages = JSON.parse(read(`messages/${locale}.json`));
    assert.ok(messages.common.skipToContent, `${locale}: common.skipToContent`);
    assert.ok(messages.serverDashboard.recovered, `${locale}: serverDashboard.recovered`);
  }
});
