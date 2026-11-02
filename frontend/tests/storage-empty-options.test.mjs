import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";
import { PRESETS } from "../lib/storage/providers.js";

const card = fs.readFileSync(new URL("../components/integrations/storage/destinations-card.jsx", import.meta.url), "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];

// Tester sheet (2 Nov): the empty state named only the five S3 providers, while the picker behind
// "Add destination" also offers FTP, SFTP, Google Drive and a folder on this server.
test("the empty state names every choice the picker offers, from the same list", () => {
  assert.match(card, /const EMPTY_STATE_PROVIDERS = PRESETS\.filter\(\(preset\) => !preset\.legacy\)\.map\(\(preset\) => preset\.value\);/);
  const offered = PRESETS.filter((preset) => !preset.legacy).map((preset) => preset.value);
  for (const value of ["aws", "r2", "b2", "wasabi", "spaces", "other", "ftp", "sftp", "google_drive_oauth", "local"]) {
    assert.ok(offered.includes(value), value);
  }
  // The service-account Drive is hidden from the picker, so it is not named either.
  assert.ok(!offered.includes("google_drive"));
});

test("\"Other\" is named as S3-compatible storage, inside step 1, and step 2 fits every destination", () => {
  assert.match(card, /provider === "other" \? t\("empty\.otherS3"\) : t\(`form\.providers\.\$\{provider\}`\)/);
  for (const locale of LOCALES) {
    const storage = JSON.parse(fs.readFileSync(new URL(`../messages/${locale}.json`, import.meta.url), "utf8")).storage;
    assert.ok(storage.empty.otherS3 && storage.empty.otherS3 !== storage.form.providers.other, locale);
    for (const provider of ["aws", "r2", "b2", "wasabi", "spaces", "ftp", "sftp", "google_drive_oauth", "local"]) {
      assert.ok(storage.form.providers[provider], `${locale} ${provider}`);
    }
  }
  // The destinations sit inside step 1 (2 Nov), so its sentence only leads into them; step 2 no
  // longer promises a login to the two that do not take one.
  assert.match(card, /\{index === 0 \? \(\s+<div className="flex flex-wrap gap-1\.5">\s+\{EMPTY_STATE_PROVIDERS\.map/);
  const en = JSON.parse(fs.readFileSync(new URL("../messages/en.json", import.meta.url), "utf8")).storage.empty;
  assert.match(en.step1, /:$/);
  assert.match(en.step2, /Google account; this server needs none\./);
});

const dialog = fs.readFileSync(new URL("../components/integrations/storage/connect-dialog.jsx", import.meta.url), "utf8");
const gitCard = fs.readFileSync(new URL("../components/integrations/git/accounts-card.jsx", import.meta.url), "utf8");
const gitDialog = fs.readFileSync(new URL("../components/integrations/git/connect-dialog.jsx", import.meta.url), "utf8");

test("each destination in the empty state opens the form already on it", () => {
  // Krishna (2 Nov). A button from the design system, disabled with the reason for a viewer,
  // named for what it does ("Add Wasabi") and not only for what it is.
  assert.match(card, /<ReasonTooltip key=\{provider\} reason=\{canManage \? null : t\("noPermission"\)\}>/);
  assert.match(card, /variant="outline"\s+size="xs"\s+disabled=\{!canManage\}\s+aria-label=\{t\("empty\.addNamed", \{ name \}\)\}/);
  assert.match(card, /setConnectPreset\(provider\);\s+setConnecting\(true\);/);
  // "Add destination" still opens it on the usual default, not on the last one picked.
  assert.match(card, /setConnectPreset\(null\);\s+setConnecting\(true\);/);
  // A fresh form per starting destination, and a close comes back to it rather than to S3.
  assert.match(card, /key=\{connectPreset \?\? "default"\}\s+initialPreset=\{connectPreset\}/);
  assert.doesNotMatch(dialog, /reset\(DEFAULT_PRESET\)/);
  assert.equal((dialog.match(/\breset\(startPreset\)/g) ?? []).length, 3);
});

test("the Git empty state matches: two columns, each provider opening its own token form", () => {
  assert.match(gitCard, /md:grid-cols-\[minmax\(0,5fr\)_minmax\(0,7fr\)\]/);
  assert.match(card, /md:grid-cols-\[minmax\(0,5fr\)_minmax\(0,7fr\)\]/);
  assert.match(gitCard, /<ReasonTooltip key=\{provider\.name\} reason=\{connectReason\}>/);
  assert.match(gitCard, /aria-label=\{t\("empty\.connectNamed", \{ name: provider\.title \}\)\}/);
  assert.match(gitCard, /setConnectProvider\(provider\);\s+setConnecting\(true\);/);
  assert.match(gitCard, /key=\{connectProvider\?\.name \?\? "picker"\}\s+initialProvider=\{connectProvider\}/);
  // Skips the picker, and a close comes back to the same form; "Connect account" still opens the picker.
  assert.match(gitDialog, /const \[chosen, setChosen\] = useState\(initialProvider\);/);
  assert.match(gitDialog, /if \(!next\) setChosen\(initialProvider\);/);
  assert.match(gitCard, /setConnectProvider\(null\);\s+setConnecting\(true\);/);
  for (const locale of LOCALES) {
    const m = JSON.parse(fs.readFileSync(new URL(`../messages/${locale}.json`, import.meta.url), "utf8"));
    assert.ok(m.git.empty.connectNamed.includes("{name}") && m.storage.empty.addNamed.includes("{name}"), locale);
    // Step 1 leads into the providers listed under it; it no longer names them itself.
    assert.doesNotMatch(m.git.empty.step1, /\{provider\}/, locale);
  }
});
