import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";

const root = new URL("..", import.meta.url).pathname;
const read = (path) => readFileSync(join(root, path), "utf8");

/*
 * "Saved" over the old screen: a success toast followed by a bare
 * `router.refresh()` shows the message, closes, and leaves the previous state
 * up until the server render lands (1-4 s on a real server). Krishna, 1 Oct:
 * "after success message old ui show for some sec then show actual ui".
 * Refresh first (`await refreshAndWait()`), then toast and close.
 *
 * Two places keep the old order on purpose, and say why in the code.
 */
const ALLOWED = new Set([
  // The toast is deferred to the dialog's unmount, which is the page showing the copy.
  "components/applications/staging/create-staging-dialog.jsx",
  // Local state already shows the removed config before the toast.
  "components/applications/fail2ban/fail2ban-panel.jsx",
]);

test("no success toast is shown before the page it describes has re-rendered", () => {
  const offenders = [];
  const walk = (dir) => {
    for (const name of readdirSync(join(root, dir))) {
      const path = join(dir, name);
      if (statSync(join(root, path)).isDirectory()) walk(path);
      else if (/\.jsx?$/.test(name) && !ALLOWED.has(path)) {
        const lines = read(path).split("\n");
        lines.forEach((line, i) => {
          if (!line.includes("router.refresh();")) return;
          const before = lines.slice(Math.max(0, i - 6), i).join("\n");
          if (/toast\.success\(/.test(before) && !/catch|toast\.error/.test(before)) offenders.push(`${path}:${i + 1}`);
        });
      }
    }
  };
  walk("components");
  walk("app");
  assert.deepEqual(offenders, []);
});

test("leaving a page waits for the next one before the toast", () => {
  const hook = read("hooks/use-refresh.js");
  assert.match(hook, /pushAndWait: \(href\) =>/);
  assert.match(hook, /startLeaving\(\(\) => router\.push\(href\)\)/);
  assert.match(read("components/databases/delete-database-dialog.jsx"), /await pushAndWait\(redirectTo\);\n\s*toast\.success/);
  assert.match(read("components/applications/delete-application-dialog.jsx"), /pushAndWait\(redirectTo\)\.then\(done\)/);
  assert.match(read("components/admin/roles/role-form.jsx"), /await pushAndWait\("\/admin\/roles"\);\n\s*toast\.success/);
});

test("database detail and health pages link back to the list", () => {
  assert.match(read("app/(app)/databases/[database]/page.jsx"), /<BackLink href="\/databases">\{t\("backToList"\)\}<\/BackLink>/);
  assert.match(read("app/(app)/databases/monitor/page.jsx"), /<BackLink href="\/databases">\{t\("backToList"\)\}<\/BackLink>/);
});
