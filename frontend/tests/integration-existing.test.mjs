import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\{\/\*[\s\S]*?\*\/\}/g, "").replace(/\/\/.*$/gm, "");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];

// 2220e0f4: the same credential answers 200 with the record that holds it, `existing: true` and a message.
test("adding storage that already exists says so, and neither announces nor tests a new one", () => {
  const s = strip(read("components/integrations/storage/connect-dialog.jsx"));
  const branch = s.slice(s.indexOf("if (data?.existing)"), s.indexOf('toast.success(t("added")'));
  assert.match(branch, /toast\.info\(data\.message \?\? t\("alreadyAdded", \{ name: created\?\.name \?\? "" \}\)\);/);
  assert.match(branch, /return;/);
  assert.ok(s.indexOf("if (data?.existing)") < s.indexOf("probeDestination("), "the probe runs before the check");
});

test("connecting a git account that already exists says so under its own label", () => {
  const s = strip(read("components/integrations/git/connect-form.jsx"));
  assert.match(s, /const label = data\?\.git_account\?\.label \?\? values\.label;/);
  assert.match(s, /if \(data\?\.existing\) toast\.info\(data\.message \?\? t\("alreadyConnected", \{ label \}\)\);/);
  assert.match(s, /onAccountConnected\?\.\(\{\s*id: data\?\.git_account\?\.id \?\? null,\s*label,/);
});

test("both fallbacks exist in all 8 languages, with their placeholder, never an ICU-escaped one", () => {
  for (const locale of LOCALES) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    assert.ok(m.git.connect.alreadyConnected.includes("{label}"), `${locale}: git`);
    assert.ok(m.storage.connect.alreadyAdded.includes("{name}"), `${locale}: storage`);
    // A straight apostrophe around a placeholder makes ICU print it literally.
    assert.ok(!/'\{(label|name)\}'/.test(m.git.connect.alreadyConnected + m.storage.connect.alreadyAdded), `${locale}: quoted placeholder`);
  }
});
