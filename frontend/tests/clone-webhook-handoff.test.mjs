import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

const progress = read("components/applications/clone/clone-progress.jsx");
const panel = read("components/applications/clone/clone-panel.jsx");
const schema = read("lib/schemas/clone.js");

const { locales } = await import("../i18n/routing.js");

test("the schema keeps the field the API sends", () => {
  // Zod strips unknown keys, so a field the schema omits is a field the screen
  // can never show however correct the backend is.
  assert.match(schema, /target_webhook: z\s*\n?\s*\.object\(\{/);
  assert.match(schema, /url: z\.string\(\)/);
});

test("the result screen is handed the webhook", () => {
  assert.match(panel, /webhook=\{completedClone\.target_webhook\}/);
  assert.match(progress, /export function CloneNextSteps\(\{ applicationId, sourceProtected, sourceHasRepository = false, webhook = null \}\)/);
});

test("the url is shown, copyable, only when there is one", () => {
  // Nothing on this box can add a webhook to somebody's GitHub, so handing the
  // URL over at the moment the clone finishes is the whole mechanism.
  assert.match(progress, /\{webhook\?\.url \? \(/);
  assert.match(progress, /<CopyButton value=\{webhook\.url\}/);
  assert.match(progress, /<CopyButton value=\{webhook\.secret\}/);
});

test("it says to add it to the repository, not merely that it exists", () => {
  const en = JSON.parse(read("messages/en.json"));
  const w = en.applications.clone.result.next.webhook;

  assert.match(w.title, /repository/i);
  // The consequence, not just the instruction — "pushes will not reach it".
  assert.match(w.body, /push/i);
});

test("every string exists in every locale", () => {
  const keys = ["title", "body", "secret", "copyUrl", "copySecret"];

  for (const locale of locales) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    const w = m.applications?.clone?.result?.next?.webhook;

    assert.ok(w, `${locale}: clone.result.next.webhook must exist`);

    for (const key of keys) {
      assert.equal(typeof w[key], "string", `${locale}: webhook.${key} must exist`);
      assert.ok(w[key].length > 0, `${locale}: webhook.${key} must not be empty`);
    }

    // The same word the Deployment screen uses, because the user meets both.
    assert.equal(
      w.secret,
      m.applications.deployment.webhook.secret,
      `${locale}: "Secret" must read the same on both screens`,
    );
  }
});
