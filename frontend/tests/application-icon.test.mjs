import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { applicationIconDataUri, imageRepository } from "../lib/applications/application-icon.js";
import { siteTypeLogo } from "../lib/applications/site-type-logo.js";

const root = path.join(import.meta.dirname, "..");
const types = [
  { name: "memos", recipe: { icon_data_uri: "data:memos", images: [{ role: "app", ref: "neosmemo/memos:0.31.0" }] } },
  { name: "ghost", recipe: { icon_data_uri: "data:ghost", images: [{ role: "app", ref: "ghost:5" }, { role: "db", ref: "mysql:8.4" }] } },
  { name: "grafana", recipe: { icon_data_uri: "data:grafana", images: [] } },
];

test("image repositories compare without registry, library/, tag or digest", () => {
  assert.equal(imageRepository("docker.io/library/nginx:1.27@sha256:abc"), "nginx");
  assert.equal(imageRepository("neosmemo/memos:0.31.0"), "neosmemo/memos");
  assert.equal(imageRepository("ghcr.io/org/app:1"), "ghcr.io/org/app");
  assert.equal(imageRepository("localhost:5000/app"), "localhost:5000/app");
});

test("an application shows its own recipe's icon, or the recipe that ships its image", () => {
  assert.equal(applicationIconDataUri({ site_type: "grafana" }, types), "data:grafana");
  assert.equal(applicationIconDataUri({ site_type: "docker", origin: { kind: "image", image: "neosmemo/memos:0.30.0" } }, types), "data:memos");
  assert.equal(applicationIconDataUri({ site_type: "docker", origin: { kind: "image", image: "docker.io/library/ghost:latest" } }, types), "data:ghost");
  // A recipe's database image is not that recipe.
  assert.equal(applicationIconDataUri({ site_type: "docker", origin: { kind: "image", image: "mysql:8.4" } }, types), null);
  assert.equal(applicationIconDataUri({ site_type: "docker", origin: { kind: "compose" } }, types), null);
});

test("Docker image and compose sites fall back to the whale", () => {
  assert.equal(siteTypeLogo("docker"), "/site-types/docker.svg");
  assert.ok(fs.existsSync(path.join(root, "public/site-types/docker.svg")));
  assert.match(fs.readFileSync(path.join(root, "components/sections/app-sidebar.jsx"), "utf8"), /iconDataUri=\{iconDataUri\}/);
});
