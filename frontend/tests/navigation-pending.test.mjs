import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";
import {
  beginNavigation,
  currentNavigation,
  holdUntilPageChanges,
  pageChanged,
  subscribeNavigation,
} from "../lib/browser/navigation-pending.js";

const root = new URL("..", import.meta.url).pathname;
const read = (path) => readFileSync(join(root, path), "utf8");

test("a wait is visible until its link lets go, and each wait has its own number", () => {
  assert.equal(currentNavigation(), null);
  const release = beginNavigation();
  const first = currentNavigation();
  assert.notEqual(first, null);
  release();
  release(); // twice is harmless
  assert.equal(currentNavigation(), null);

  const again = beginNavigation();
  assert.ok(currentNavigation() > first);
  again();
});

test("a link that unmounts mid-wait keeps the bar until the page changes", () => {
  let calls = 0;
  const off = subscribeNavigation(() => calls++);
  holdUntilPageChanges(beginNavigation());
  assert.notEqual(currentNavigation(), null);
  pageChanged();
  assert.equal(currentNavigation(), null);
  assert.equal(calls, 2);
  off();
});

test("every in-app link reports its wait: no file imports next/link directly", () => {
  const offenders = [];
  const walk = (dir) => {
    for (const name of readdirSync(join(root, dir))) {
      const path = join(dir, name);
      if (statSync(join(root, path)).isDirectory()) walk(path);
      else if (/\.jsx?$/.test(name) && path !== "components/ui/app-link.jsx") {
        const src = read(path);
        if (/import Link from "next\/link"/.test(src)) offenders.push(path);
      }
    }
  };
  for (const dir of ["app", "components", "lib", "hooks"]) walk(dir);
  assert.deepEqual(offenders, []);
});

test("the bar is mounted once, at the root, and the sidebar item spins", () => {
  assert.match(read("app/layout.jsx"), /<NavigationProgress \/>/);
  assert.match(read("components/sections/app-sidebar.jsx"), /<PendingNavIcon name=\{item\.icon\} \/>/);
});

test("Restart asks first only when the service is running", () => {
  const src = read("components/services/service-actions.jsx");
  assert.match(src, /action === "restart" && service\.status === "active"/);
  const en = JSON.parse(read("messages/en.json")).services.confirm.restart;
  assert.equal(en.title, "Restart {name}?");
});
