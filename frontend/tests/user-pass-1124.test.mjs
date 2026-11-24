import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];

// 24 Nov (Krishna): fixes from using the panel as a user.
test("PHP Settings lists the saved value as a choice of its own", () => {
  const panel = read("components/applications/php/php-panel.jsx");
  assert.match(panel, /const saved = form\.formState\.defaultValues\?\.\[name\];/);
  assert.match(panel, /\{choices\.map\(\(option\) => \(/);
});

test("a size measured during setup is not shown, and is measured again", async () => {
  const { sizeMeasuredDuringSetup, shownSizeBytes } = await import("../lib/applications/size-measurement.js");
  const during = { directory_size_bytes: 5120, created_at: "24-11-2026 06:40:02", directory_size_measured_at: "24-11-2026 06:40:30" };
  const after = { ...during, directory_size_measured_at: "24-11-2026 06:46:04" };
  assert.equal(sizeMeasuredDuringSetup(during), true);
  assert.equal(shownSizeBytes(during), null);
  assert.equal(sizeMeasuredDuringSetup(after), false);
  assert.equal(shownSizeBytes(after), 5120);
  assert.equal(shownSizeBytes({ directory_size_bytes: null }), null);
  assert.match(read("components/applications/site-facts-card.jsx"), /await measureApplicationSize\(application\.id\);\s*await refreshAndWait\(\);/);
});

test("a tag typed with an image name is kept when that image is picked", () => {
  const setup = read("components/applications/docker-image-setup.jsx");
  assert.match(setup, /choose\(joinImageRef\(option\.image, typedTag\)\);/);
  assert.match(setup, /if \(option\) chooseOption\(option\);/);
  assert.match(setup, /onClick=\{\(\) => chooseOption\(option\)\}/);
});

test("deleting an application does not offer to remove a shared system user", () => {
  const dlg = read("components/applications/delete-application-dialog.jsx");
  assert.match(dlg, /othersOnUser > 0 \? \(\s*<p className="text-xs leading-5 text-muted-foreground">\s*\{t\("systemUserShared"/);
  assert.match(dlg, /&& !\(othersOnUser > 0\) && removeSystemUser/);
  assert.doesNotMatch(dlg, /removeSystemUser \? t\("removeSystemUserOn"\) : t\("systemUserStays"/);
  for (const l of LOCALES) assert.match(JSON.parse(read(`messages/${l}.json`)).applications.delete.systemUserShared, /\{count, plural,/, l);
});

test("the new database's password is masked like its connection string", () => {
  const card = read("components/databases/created-credentials.jsx");
  assert.match(card, /field\.key === "password" \? \(\s*<MaskedValue value=\{field\.value\} \/>/);
});

test("the Backups page refreshes its times once a minute, quietly", () => {
  assert.match(read("components/applications/backups/backups-panel.jsx"), /<AutoRefresh intervalMs=\{60000\} stopAfterMs=\{30 \* 60 \* 1000\} quiet \/>/);
  assert.match(read("components/ui/auto-refresh.jsx"), /if \(!stopped \|\| quiet\) return null;/);
});

test("Docker memory reads as GB/MB, and the header row fits its content", async () => {
  const { dockerMemoryText } = await import("../lib/docker/memory-text.js");
  assert.equal(dockerMemoryText("1g"), "1 GB");
  assert.equal(dockerMemoryText("512m"), "512 MB");
  assert.equal(dockerMemoryText("n/a"), "n/a");
  assert.match(read("components/applications/site-facts-card.jsx"), /\|\| facts\.length <= 6 \? "xl:grid-cols-none xl:grid-flow-col xl:auto-cols-\[minmax\(0,auto\)\]"/);
  assert.match(read("components/applications/site-facts-card.jsx"), /\$\{strip \? "pointer-coarse:hidden" : ""\}/);
});

test("the PHP memory notes exist in every locale", () => {
  for (const l of LOCALES) {
    const b = JSON.parse(read(`messages/${l}.json`)).applications.php.budget;
    for (const k of ["overSelf", "overShared"]) assert.match(b[k], /\{required\}[\s\S]*\{total\}/, `${l}.${k}`);
  }
});
