import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

import { defaultConfig, describeDestination, hasCredentials, presetForProvider, providerForPreset, secretFieldsFor } from "../lib/storage/providers.js";
import { createStorageDestinationSchema } from "../lib/schemas/storage.js";

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\{\/\*[\s\S]*?\*\/\}/g, "").replace(/\/\/.*$/gm, "");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];

// 473c3616 (#14): provider `local`, a folder on this server, config `{path}` only.
test("This server is a preset with one field, the backend's suggested folder, and nothing secret", () => {
  assert.equal(providerForPreset("local"), "local");
  assert.equal(presetForProvider("local"), "local");
  assert.deepEqual(defaultConfig("local"), { path: "/var/backups/panel" });
  assert.deepEqual(secretFieldsFor("local"), []);
  assert.equal(hasCredentials("local"), false);
  assert.equal(hasCredentials("sftp"), true);
});

test("the path must be absolute and cannot climb; which roots are allowed is the backend's", () => {
  const schema = createStorageDestinationSchema("local");
  const ok = (path) => schema.safeParse({ name: "Here", config: { path } }).success;
  assert.ok(ok("/var/backups/panel"));
  assert.ok(ok("/mnt/disk2/backups"));
  assert.ok(!ok(""));
  assert.ok(!ok("var/backups"));
  assert.ok(!ok("/var/backups/../etc"));
});

test("the row names the folder, not an empty remote address", () => {
  assert.deepEqual(describeDestination({ provider: "local", config: { path: "/mnt/disk2/backups" } }), { location: "/mnt/disk2/backups", address: null });
  const row = strip(read("components/integrations/storage/destination-row.jsx"));
  assert.match(row, /\{!secrets \? null : destination\.has_credentials \?/);
  assert.match(row, /\{secrets \? \(\s*<DropdownMenuItem onSelect=\{onReplace\}/);
  assert.match(row, /t\("row\.diskFree", \{ free, total \}\)/);
  assert.match(row, /local_no_space: "failedLocalNoSpace"/);
  assert.match(row, /t\(hasCredentials\(destination\.provider\) \? "row\.neverTested" : "row\.neverTestedFolder"\)/);
});

test("saving says whether the folder was created or found; the folder itself has no prefix", () => {
  const connect = strip(read("components/integrations/storage/connect-dialog.jsx"));
  assert.match(connect, /toast\.success\(t\("added"\), data\?\.folder\?\.message \? \{ description: data\.folder\.message \} : undefined\)/);
  const edit = strip(read("components/integrations/storage/edit-dialog.jsx"));
  assert.match(edit, /const moved = data\?\.folder\?\.message && formValues\.config\?\.path !== destination\?\.config\?\.path;/);
  assert.match(edit, /\.\.\.\(provider === "local" \? \{\} : \{ prefix: formValues\.prefix\?\.trim\(\) \?\? "" \}\)/);
  assert.match(strip(read("components/integrations/storage/destination-form-fields.jsx")), /\{provider === "local" \? null : \(\s*<FormField\s*control=\{form\.control\}\s*name="prefix"/);
});

test("a relative download link opens on the API's host, an absolute one is kept", () => {
  assert.match(read("components/backups/download-backup-button.jsx"), /link\.href = new URL\(url, process\.env\.NEXT_PUBLIC_API_URL\)\.href;/);
  const api = "https://api.example.test";
  assert.equal(new URL("/api/storage-destinations/3/local-file?key=a&signature=b", api).href, "https://api.example.test/api/storage-destinations/3/local-file?key=a&signature=b");
  assert.equal(new URL("https://bucket.s3.amazonaws.com/x?X-Amz-Signature=1", api).href, "https://bucket.s3.amazonaws.com/x?X-Amz-Signature=1");
});

test("every new string exists in all 8 languages", () => {
  for (const locale of LOCALES) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    const f = m.storage.form;
    for (const [where, value] of [["providers.local", f.providers.local], ["fields.path", f.fields.path], ["help.path", f.help.path], ["localWarning", f.localWarning], ["localPathChangeWarning", f.localPathChangeWarning], ["validation.localPathAbsolute", m.validation.localPathAbsolute], ["row.diskFree", m.storage.row.diskFree]]) {
      assert.ok(value, `${locale}: ${where}`);
    }
    for (const key of ["neverTestedFolder", "failedLocalMissing", "failedLocalForeign", "failedLocalNotWritable", "failedLocalNoSpace", "failedLocalPath"]) assert.ok(m.storage.row[key], `${locale}: row.${key}`);
  }
});
