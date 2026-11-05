// RC-33: GET /docker/images/tags marks pre-releases and never recommends one.
import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { cleanup, render, screen, waitFor } = await import("@testing-library/react");
const { imageTagsResponseSchema } = await import("../lib/schemas/docker.js");

const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const all = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(readFileSync(`messages/${l}.json`, "utf8"))]));
const messages = all.en;
const m = messages.applications.dockerImage;
let DockerPickerHarness;

before(async () => {
  ({ DockerPickerHarness } = await loadComponent("tests/support/docker-picker-harness.jsx", {
    "@/lib/api/docker": "tests/support/docker-api-stub.js",
  }));
});
after(() => cleanup());

function show(tags) {
  globalThis.__dockerApi = {
    tags: async () => ({ data: tags }),
    inspect: async () => ({ data: { found: true, suggested_port: 8080, exposed_ports: [8080], port_confidence: "declared" } }),
    search: async () => ({ data: { results: [] } }),
  };
  cleanup();
  render(
    createElement(DockerPickerHarness, {
      messages,
      defaultValues: { name: "app", image: "openjdk", docker_env: [], docker_volumes: [] },
      onForm: () => {},
    }),
  );
}

const text = () => document.body.textContent;

test("prerelease is read, and an older API without it means not a pre-release", () => {
  const parsed = imageTagsResponseSchema.parse({ recommended: null, tags: [{ name: "28-ea" , prerelease: true }, { name: "21" }] });
  assert.deepEqual(parsed.tags.map((tag) => tag.prerelease), [true, false]);
});

test("only pre-releases: the newest is preselected but never called recommended or stable", async () => {
  show({ recommended: null, tags: [{ name: "28-ea-oraclelinux9", prerelease: true }, { name: "27-ea", prerelease: true }] });
  await waitFor(() => assert.equal(text().includes(m.prereleaseHelp), true));
  assert.equal(screen.getByRole("combobox", { name: m.versionLabel }).textContent.includes("28-ea-oraclelinux9"), true);
  assert.equal(text().includes(m.versionHelp), false, "no 'We picked the newest stable version'");
});

test("a recommended stable tag still reads as before", async () => {
  show({ recommended: "21", tags: [{ name: "28-ea", prerelease: true }, { name: "21" }] });
  await waitFor(() => assert.equal(text().includes(m.versionHelp), true));
  assert.equal(text().includes(m.prereleaseHelp), false);
});

test("the two new strings exist and are translated in every language", () => {
  for (const locale of LOCALES) {
    const d = all[locale].applications.dockerImage;
    assert.ok(d.prerelease && d.prereleaseHelp, locale);
    if (locale !== "en") assert.notEqual(d.prereleaseHelp, m.prereleaseHelp, locale);
  }
});
