import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { workerFormSchema, WORKER_FORM_DEFAULTS } from "../lib/schemas/worker.js";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];

test("every validation code the schemas emit is a sentence in every locale", () => {
  // Found as raw codes on screen: Staging, PHP settings, Storage, Workers.
  const keys = ["integer", "portRange", "stagingDomainRequired", "stagingDomainInvalid", "max12", "max4000", "max16384", "phpSize", "range", "functionList", "noSections", "hostIsUrl", "rootFormat", "rootTraversal", "noEnvironmentLine", "sftpAuthRequired"];
  for (const l of LOCALES) {
    const m = JSON.parse(read(`messages/${l}.json`));
    for (const k of keys) assert.ok(m.validation[k], `${l} validation.${k}`);
    assert.ok(m.settings.validation.invalidHour, `${l} settings.validation.invalidHour`);
  }
  assert.match(read("lib/settings/validation-message.js"), /"invalidHour"/);
});

test("an environment= line in a worker's extra config is refused before it breaks the worker", () => {
  const base = { ...WORKER_FORM_DEFAULTS, name: "q", command: "sleep 1" };
  assert.equal(workerFormSchema.safeParse({ ...base, extra_config: "  Environment = A=1" }).success, false);
  assert.equal(workerFormSchema.safeParse({ ...base, extra_config: "startsecs=5\nstopsignal=INT" }).success, true);
});

test("a turned-off worker is labelled for what it is, not a boot promise", () => {
  for (const l of LOCALES) {
    const tag = JSON.parse(read(`messages/${l}.json`)).applications.workers.disabledTag;
    assert.doesNotMatch(tag, /boot|arrancar|बूट|Systemstart|démarr|inicializ|起動時|загрузк/i, l);
  }
});

test("New folder refuses a name already in the folder (the API answers 200 for it)", () => {
  const dialog = read("components/applications/files/new-folder-dialog.jsx");
  assert.match(dialog, /if \(existingNames\.includes\(values\.name\.trim\(\)\)\)/);
  assert.match(read("components/applications/files/files-panel.jsx"), /existingNames=\{files\.map\(\(f\) => f\.name\)\}/);
  for (const l of LOCALES) assert.ok(JSON.parse(read(`messages/${l}.json`)).applications.files.newFolder.taken.includes("{name}"), l);
});

test("Japanese uses the full-width question mark after Japanese text", () => {
  assert.doesNotMatch(read("messages/ja.json"), /[぀-ヿ㐀-鿿]\?/);
});

test("no schema emits a message code that has no sentence", () => {
  // Swept both ways a code is written: `.min(1, "code")` and `message: "code"`.
  // Firewall codes are translated by the add-rule form itself; staging's
  // modeRequired is never rendered (the dialog uses its own state).
  const handled = new Set(["requiredField", "invalidSource", "nameTooLong", "portOrder", "portShape", "modeRequired"]);
  const en = JSON.parse(read("messages/en.json"));
  const known = new Set([...Object.keys(en.validation), ...Object.keys(en.settings.validation), ...handled]);
  const files = fs.readdirSync("lib/schemas").map((f) => `lib/schemas/${f}`);
  const missing = [];
  for (const f of files) {
    const s = read(f);
    for (const m of s.matchAll(/message:\s*"([a-z][A-Za-z0-9_]*)"/g)) if (!known.has(m[1])) missing.push(`${f}:${m[1]}`);
    for (const m of s.matchAll(/\.(?:min|max|regex|refine|int|startsWith|length)\((?:[^()]|\([^()]*\))*?"([A-Za-z_][A-Za-z0-9_]*)"\s*\)/g)) if (!known.has(m[1])) missing.push(`${f}:${m[1]}`);
  }
  assert.deepEqual(missing, []);
});
