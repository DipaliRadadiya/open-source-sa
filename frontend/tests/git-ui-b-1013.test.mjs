import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

// Git page, Krishna 13 Oct (on the original layout): checks on the right, standard warnings.
const read = (p) => fs.readFileSync(p, "utf8");

test("check buttons sit on the right of the row, beside the menu", () => {
  const row = read("components/integrations/git/account-row.jsx");
  const right = row.indexOf('max-sm:ps-11 sm:ms-auto sm:justify-end');
  assert.ok(right > 0);
  assert.ok(row.indexOf('t("actions.check")') > right && row.indexOf("<DropdownMenu>") > right);
});

test("form warnings use the panel's Caution box", () => {
  const form = read("components/integrations/git/connect-form.jsx");
  assert.match(form, /<Caution size="md" className="mt-3">/);
  assert.match(form, /<Caution tone="destructive" size="md">/);
  assert.match(read("components/integrations/git/replace-token-dialog.jsx"), /<Caution tone="destructive" size="md">/);
});
