import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { FIELDS, secretFieldsFor } from "../lib/storage/providers.js";

// Storage page, Krishna 13 Oct: placeholders on every typed field, a clearer delete dialog.
const read = (p) => fs.readFileSync(p, "utf8");
const en = JSON.parse(read("messages/en.json")).storage;

test("every secret and textarea field has a placeholder", () => {
  for (const provider of Object.keys(FIELDS)) {
    for (const field of secretFieldsFor(provider)) {
      assert.ok(en.form.placeholders[field.name], `${field.name} has no placeholder`);
    }
  }
  assert.match(
    read("components/integrations/storage/destination-form-fields.jsx"),
    /<PasswordInput[\s\S]{0,120}placeholder=\{hint\}/,
  );
});

test("replace credentials says an empty box keeps the current value", () => {
  const dialog = read("components/integrations/storage/replace-credentials-dialog.jsx");
  assert.equal(dialog.match(/placeholder=\{t\("keepCurrent"\)\}/g)?.length, 2);
  assert.ok(en.replace.keepCurrent);
});

test("delete dialog names the destination and shows the rule as a note", () => {
  const card = read("components/integrations/storage/destinations-card.jsx");
  assert.match(card, /t\("delete\.titleNamed", \{ name:/);
  assert.match(card, /<Note>\{t\("delete\.warning"\)\}<\/Note>/);
  assert.equal(en.delete.titleNamed, "Delete {name}?");
  assert.equal(en.delete.title, undefined);
});

test("a failed test offers Replace credentials as a link on the red line", () => {
  const row = read("components/integrations/storage/destination-row.jsx");
  assert.equal(row.match(/<ReplaceLink onClick=\{onReplace\} \/>/g)?.length, 2, "fresh and stored failures");
  assert.doesNotMatch(row, /className="h-7"/, "no button under the line");
  assert.equal(en.row.replaceHint, undefined);
});

test("edit destination: folder warning is a Caution box, the two notes share one Note", () => {
  assert.match(read("components/integrations/storage/destination-form-fields.jsx"), /<Caution size="md" className="mt-1">\s*<p className="leading-relaxed">\{t\("prefixChangeWarning"\)\}/);
  const edit = read("components/integrations/storage/edit-dialog.jsx");
  // The credentials line is left out for a provider with none ("This server", #14).
  assert.match(edit, /<Note>\s*<ul[^>]*>\s*\{hasCredentials\(provider\) \? <li>\{t\("credentialsUntouched"\)\}<\/li> : null\}\s*<li>\{t\("providerLocked"/);
});

test("bot traffic labels v7-list bots and the PHP hint mentions pool lines", () => {
  const card = read("components/applications/bot-blocker/bot-traffic-card.jsx");
  assert.match(card, /new Set\(\["training", "search", "agent", "custom", "legacy"\]\)/);
  const msgs = JSON.parse(read("messages/en.json")).applications;
  assert.equal(msgs.botBlocker.traffic.kinds.legacy, "v7 list");
  assert.match(read("components/applications/php/php-panel.jsx"), /php\.isolation_supported \? "hints\.directivesPool" : "hints\.directives"/);
  assert.match(msgs.php.hints.directivesPool, /env\[APP_ENV\]/);
});
