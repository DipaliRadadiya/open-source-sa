import assert from "node:assert/strict";
import fs from "node:fs";
import { test } from "node:test";
import { createStorageDestinationSchema, editStorageDestinationSchema, storageDestinationsResponseSchema } from "../lib/schemas/storage.js";
import { defaultConfig } from "../lib/storage/providers.js";
import { localStorageSettings } from "../lib/storage/local-settings.js";

const CUSTOM = { allowed_roots: ["/srv/archive", "/data/backups"], default_path: "/srv/archive/panel" };
const values = (path) => ({ name: "Backups", config: { path } });
const read = (path) => fs.readFileSync(path, "utf8");

test("RC-39 metadata survives response parsing; an older response stays valid", () => {
  assert.deepEqual(storageDestinationsResponseSchema.parse({ local: CUSTOM }).local, CUSTOM);
  assert.equal(storageDestinationsResponseSchema.parse({}).local, undefined);
  assert.equal(storageDestinationsResponseSchema.parse({ local: null }).local, null);
});

test("the server's default wins; older APIs have one compatibility fallback", () => {
  assert.deepEqual(defaultConfig("local", CUSTOM), { path: "/srv/archive/panel" });
  assert.deepEqual(defaultConfig("local"), { path: "/var/backups/panel" });
  assert.deepEqual(defaultConfig("ftp", CUSTOM), defaultConfig("ftp"));
  assert.deepEqual(defaultConfig("aws", CUSTOM), defaultConfig("aws"));
  assert.deepEqual(localStorageSettings(CUSTOM), CUSTOM);
});

test("create and edit validate the advertised roots, not the previous fixed list", () => {
  const destination = { provider: "local", config: { path: "/srv/archive/existing" } };
  const schemas = [createStorageDestinationSchema("local", CUSTOM), editStorageDestinationSchema(destination, CUSTOM)];
  for (const schema of schemas) {
    for (const path of ["/srv/archive/panel", "/srv/archive/existing", "/data/backups/panel", "/data/backups/panel/"]) {
      assert.ok(schema.safeParse(values(path)).success, path);
    }
    for (const path of ["/var/backups/panel", "/srv/archive-other/panel", "/srv/archives/panel", "/etc/backups"]) {
      const result = schema.safeParse(values(path));
      assert.equal(result.success, false, path);
      assert.ok(result.error.issues.some((issue) => issue.message === "localPathAllowed"), path);
    }
    for (const path of ["/srv/archive", "/srv/archive/", "/data/backups///"]) {
      const result = schema.safeParse(values(path));
      assert.equal(result.success, false, path);
      assert.equal(result.error.issues[0].message, "localPathRoot", path);
    }
    for (const path of ["srv/archive/panel", "/srv/archive/../etc", "/srv/archive/./panel", ""]) {
      assert.equal(schema.safeParse(values(path)).success, false, path);
    }
  }
});

test("empty advertised roots are not replaced with the old defaults", () => {
  const settings = { allowed_roots: [], default_path: "" };
  assert.deepEqual(localStorageSettings(settings), settings);
  assert.deepEqual(defaultConfig("local", settings), { path: "" });
  assert.equal(createStorageDestinationSchema("local", settings).safeParse(values("/var/backups/panel")).success, false);
});

test("older APIs still decide unadvertised custom roots themselves", () => {
  assert.ok(createStorageDestinationSchema("local").safeParse(values("/custom/backups")).success);
});

test("SSR metadata reaches both dialogs and the common hints", () => {
  assert.match(read("lib/storage/get-storage.js"), /localSettings: result\.data\?\.local \?\? null/);
  assert.match(read("app/(app)/integrations/storage/page.jsx"), /localSettings=\{list\.localSettings\}/);
  assert.equal((read("components/integrations/storage/destinations-card.jsx").match(/localSettings=\{localSettings\}/g) ?? []).length, 2);
  const connect = read("components/integrations/storage/connect-dialog.jsx");
  for (const expression of ["defaultConfig(providerForPreset(startPreset), localSettings)", "defaultConfig(providerForPreset(nextPreset), localSettings)", "defaultConfig(nextProvider, localSettings)", "createStorageDestinationSchema(preset, localSettings)"]) assert.ok(connect.includes(expression), expression);
  const edit = read("components/integrations/storage/edit-dialog.jsx");
  assert.match(edit, /config\[field\.name\] = current \?\? defaults\[field\.name\]/, "existing paths take precedence over the default");
  assert.match(edit, /editStorageDestinationSchema\(destination, localSettings\)/);
  const fields = read("components/integrations/storage/destination-form-fields.jsx");
  assert.match(fields, /roots: local\.allowed_roots\.join\(", "\)/);
  assert.match(fields, /path: local\.default_path/);
  assert.match(fields, /t\("help\.noLocalRoots"\)/);
});

test("all eight languages use dynamic folder help and placeholders", () => {
  for (const locale of ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"]) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    assert.ok(m.storage.form.help.path.includes("{roots}"), locale);
    assert.equal(m.storage.form.placeholders.path, "{path}", locale);
    assert.ok(m.storage.form.help.noLocalRoots && m.validation.localPathAllowed, locale);
    for (const text of [m.storage.form.help.path, m.validation.localPathRoot, m.validation.localPathAbsolute]) {
      assert.ok(!/\/var\/backups|\/mnt|\/media/.test(text), `${locale}: no fixed locations in help`);
    }
  }
});
