import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (p) => fs.readFileSync(p, "utf8");

test("the Deploy card follows the application a refresh brings back", () => {
  const src = read("components/applications/deployment/deployment-panel.jsx");
  assert.match(src, /if \(initial !== renderedFrom\) \{\s*setRenderedFrom\(initial\);\s*setApplication\(initial\);\s*\}/);
});

test("the dropdown chooses from the keyboard and says what it is", () => {
  const src = read("components/ui/combobox.jsx");
  assert.match(src, /if \(event\.key === "Enter"\)/);
  assert.match(src, /event\.key === "ArrowDown" \|\| event\.key === "ArrowUp"/);
  assert.match(src, /role="listbox"/);
  assert.match(src, /role="option"\s*aria-selected=\{isSelected\}/);
  assert.match(src, /aria-activedescendant=/);
});

test("a read-only role sees Deploy disabled with the reason, not no button", () => {
  const src = read("components/applications/deployment/deploy-card.jsx");
  assert.doesNotMatch(src, /\{canManage \? \(\s*<CardAction>/);
  assert.match(src, /!canManage \? t\("history\.noPermission"\)/);
  assert.match(src, /disabled=\{deploying \|\| unlinked \|\| !canManage\}/);
});
