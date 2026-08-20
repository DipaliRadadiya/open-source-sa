import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { installHome } from "../lib/services/install-home.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");

/*
 * The bug this file exists for: the Services page sent every failed install to
 * /setup. That is a dead end for anything the setup page considers "installed",
 * and the setup catalog considers a component installed as soon as ANY part of
 * it is:
 *
 *     if ($component->installed()) return 'installed';   // wins over everything
 *
 * DatabaseComponent::installed() is "any engine running". So with MySQL up, a
 * failed MongoDB install rendered on Services as "Failed — Open setup", and
 * /setup showed a green "You're all set", 100% complete, with no MongoDB row on
 * the page at all.
 */

test("a failed database engine goes to the databases page, not setup", () => {
  for (const engine of ["mongodb", "mysql", "mariadb"]) {
    assert.equal(
      installHome(engine).href,
      "/databases",
      `${engine} must land where engine-state.jsx renders its failure and retry`,
    );
  }
});

test("a failed PHP version goes to the PHP page", () => {
  for (const key of ["php8.5-fpm", "php8.4-fpm", "php7.4-fpm"]) {
    assert.equal(installHome(key).href, "/php");
  }
});

test("fail2ban goes to its own page", () => {
  assert.equal(installHome("fail2ban").href, "/fail2ban");
});

test("anything else still falls back to setup", () => {
  // Every service the backend can mark install_failed is covered above; a new
  // one added later has no home yet, and setup is the right place to start.
  for (const key of ["nginx", "redis", "supervisor", "something-new", "", undefined]) {
    assert.equal(installHome(key).href, "/setup");
  }
});

test("php matching does not catch the plain engines or vice versa", () => {
  // `mysql` must not match the php pattern, and a php unit must not match the
  // engine one — both were single regexes away from each other.
  assert.notEqual(installHome("php8.5-fpm").href, "/databases");
  assert.notEqual(installHome("mysql").href, "/php");
  // Not every string containing "php" is a version unit.
  assert.equal(installHome("phpmyadmin").href, "/setup");
});

test("the services catalog is fully covered by the home map", () => {
  /*
   * Reads the backend's own catalog rather than a copy of it. Only entries with
   * an `install` marker can ever reach `install_failed`, so those are exactly
   * the keys that need a home — and if the backend adds one, this fails instead
   * of the new service quietly inheriting /setup.
   */
  const config = path.join(root, "..", "backend", "config", "server.php");
  if (!fs.existsSync(config)) return; // frontend-only checkout

  const source = fs.readFileSync(config, "utf8");
  const installable = [...source.matchAll(/'key' => '([^']+)'.*?'install' => \[/g)].map(
    (m) => m[1],
  );

  assert.ok(installable.length > 0, "expected the catalog to declare installable services");

  for (const key of installable) {
    assert.notEqual(
      installHome(key).href,
      "/setup",
      `${key} can fail to install and has no home — it would land on the setup page, ` +
        `which reports a component installed as soon as any part of it is`,
    );
  }
});
