import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import {
  dockerCreateExtras,
  joinImageRef,
  looksLikeImageRef,
  splitImageRef,
  volumeNameFor,
} from "../lib/docker/image-ref.js";

const picker = readFileSync("components/applications/docker-image-setup.jsx", "utf8");
const form = readFileSync("components/applications/create-application-form.jsx", "utf8");
const card = readFileSync("components/applications/container-card.jsx", "utf8");
const LOCALES = readdirSync("messages").filter((f) => f.endsWith(".json")).map((f) => f.replace(".json", ""));

test("a colon before the last slash is a registry port, not a tag", () => {
  assert.deepEqual(splitImageRef("memos"), { repository: "memos", tag: "", digest: "" });
  assert.deepEqual(splitImageRef("nginx:1.27-alpine"), { repository: "nginx", tag: "1.27-alpine", digest: "" });
  assert.deepEqual(splitImageRef("ghcr.io/org/app:1.2"), { repository: "ghcr.io/org/app", tag: "1.2", digest: "" });
  assert.deepEqual(splitImageRef("localhost:5000/app"), { repository: "localhost:5000/app", tag: "", digest: "" });
  assert.deepEqual(splitImageRef("localhost:5000/app:2"), { repository: "localhost:5000/app", tag: "2", digest: "" });
  assert.equal(splitImageRef("app@sha256:abc").digest, "sha256:abc");
  assert.equal(joinImageRef("usememos/memos", "0.31.0"), "usememos/memos:0.31.0");
});

test("a reference with shell characters or spaces is refused, like the API's rule", () => {
  assert.equal(looksLikeImageRef("ghcr.io/org/app:1.2"), true);
  assert.equal(looksLikeImageRef("nginx; rm -rf /"), false);
  assert.equal(looksLikeImageRef("my image"), false);
  assert.equal(looksLikeImageRef("-flag"), false);
});

test("volume names are per application and never collide with each other", () => {
  assert.equal(volumeNameFor("My Memos", "/var/opt/memos"), "my-memos-memos");
  assert.equal(volumeNameFor("jelly", "/config", ["jelly-config"]), "jelly-config-2");
  assert.equal(volumeNameFor("", "/"), "app-data");
});

test("only ticked folders become mounts; blank env rows are dropped", () => {
  const { mounts, env } = dockerCreateExtras({
    applicationName: "jelly",
    volumes: [
      { path: "/config", checked: true },
      { path: "/cache", checked: false },
      { path: "/media/config", checked: true },
    ],
    env: [{ key: "TZ", value: "UTC" }, { key: " ", value: "x" }],
  });
  assert.deepEqual(mounts, [
    { volume: "jelly-config", path: "/config" },
    { volume: "jelly-config-2", path: "/media/config" },
  ]);
  assert.deepEqual(env, [{ key: "TZ", value: "UTC" }]);
});

test("the detected port is text with a Change link, not an input", () => {
  // The input exists only when asked for (no declared port) or after Change.
  assert.match(picker, /portAsked \|\| portEditing \?/);
  assert.match(picker, /t\("portDetected"\)/);
  assert.match(picker, /onClick=\{\(\) => setPortEditing\(true\)\}/);
  // A failed inspect is "we could not ask", never a declared-nothing answer.
  assert.match(picker, /state: "unavailable", data: null/);
  assert.match(card, /matches && !portEditing/);
});

test("the create form no longer defaults a container to port 80", () => {
  assert.match(form, /selected\.name === "docker" && field\.name === "container_port"/);
});

// The payload rules themselves are behaviour-tested in docker-create-request.test.mjs.
test("the create request takes its volumes and env from dockerCreateFields", () => {
  assert.match(form, /dockerSent = dockerCreateFields\(/);
  assert.match(form, /Object\.assign\(payload, dockerSent\.fields\)/);
});

test("every string the picker renders exists in every locale", () => {
  const keys = new Set([
    ...[...picker.matchAll(/\bt(?:\.rich)?\("([a-zA-Z]+)"/g)].map((m) => m[1]),
    ...[...form.matchAll(/t\("dockerImage\.([a-zA-Z]+)"/g)].map((m) => m[1]),
    ...[...card.matchAll(/tImage\("([a-zA-Z]+)"/g)].map((m) => m[1]),
  ]);
  assert.ok(keys.size > 40);
  for (const locale of LOCALES) {
    const strings = JSON.parse(readFileSync(`messages/${locale}.json`, "utf8")).applications.dockerImage;
    for (const key of keys) assert.ok(strings?.[key], `${locale}: applications.dockerImage.${key}`);
  }
});
