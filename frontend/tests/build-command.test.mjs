import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { nextBuildCommand } from "../lib/applications/build-command.js";

// The backend's `server.deployments.package_manager_scripts`, as the package_manager field sends them.
const templates = {
  npm: "npm ci\nnpm run build --if-present\n",
  yarn: "yarn install --frozen-lockfile\ngrep -q '\"build\"' package.json && yarn build\n",
};

test("a static or PHP git app gets no npm build command", () => {
  for (const renderingType of ["static", "php", undefined]) {
    assert.equal(nextBuildCommand({ renderingType, packageManager: "npm", templates, current: "" }), undefined, String(renderingType));
  }
});

test("switching from client-side rendering to static clears the filled npm command", () => {
  assert.equal(nextBuildCommand({ renderingType: "static", packageManager: "npm", templates, current: templates.npm }), "");
  assert.equal(nextBuildCommand({ renderingType: "php", packageManager: "yarn", templates, current: templates.yarn }), "");
});

test("a command the user typed is never replaced or cleared", () => {
  const typed = "hugo --minify";
  for (const renderingType of ["static", "csr", "ssr"]) {
    assert.equal(nextBuildCommand({ renderingType, packageManager: "npm", templates, current: typed }), undefined, renderingType);
  }
});

test("a Node app gets its package manager's command, and follows a change of manager", () => {
  assert.equal(nextBuildCommand({ renderingType: "csr", packageManager: "npm", templates, current: "" }), templates.npm);
  assert.equal(nextBuildCommand({ renderingType: "ssr", packageManager: "yarn", templates, current: templates.npm }), templates.yarn);
  assert.equal(nextBuildCommand({ renderingType: "ssr", packageManager: "yarn", templates, current: templates.yarn }), undefined);
});

test("the create form re-runs the build command rule when the rendering type changes", () => {
  const src = fs.readFileSync(new URL("../components/applications/create-application-form.jsx", import.meta.url), "utf8");
  assert.match(src, /nextBuildCommand\(\{\s*renderingType,/);
  assert.match(src, /\}, \[form, packageManager, renderingType, selected\]\);/);
});
