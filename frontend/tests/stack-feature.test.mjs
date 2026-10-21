import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { unavailableOnServer } from "../lib/permissions/stack-feature.js";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

// Mirrors VisiblePermissions: the API hides these from everyone when the
// server does not run them, so "ask an administrator" was wrong (20 Oct).
test("stack-only features follow the server's serving profiles", () => {
  const docker = ["docker"];
  for (const feature of ["php", "node", "database"]) assert.equal(unavailableOnServer(feature, docker), true, feature);
  for (const feature of ["docker", "registry"]) assert.equal(unavailableOnServer(feature, docker), false, feature);
  assert.equal(unavailableOnServer("node", ["node", "static"]), false);
  assert.equal(unavailableOnServer("php", ["node"]), true);
  assert.equal(unavailableOnServer("docker", ["php"]), true);
  // Unknown profiles or a feature with no stack rule: never claimed unavailable.
  assert.equal(unavailableOnServer("php", null), false);
  assert.equal(unavailableOnServer("cronjob", ["docker"]), false);
});

test("the five stack-only pages pass their feature to PermissionDenied", () => {
  for (const [page, feature] of [["php", "php"], ["node", "node"], ["databases", "database"], ["docker", "docker"], ["integrations/registries", "registry"]]) {
    assert.match(read(`app/(app)/${page}/page.jsx`), new RegExp(`<PermissionDenied title=\\{t\\("title"\\)\\} feature="${feature}" />`), page);
  }
  assert.match(read("components/sections/permission-denied.jsx"), /title=\{t\("unavailableTitle"\)\}/);
});
