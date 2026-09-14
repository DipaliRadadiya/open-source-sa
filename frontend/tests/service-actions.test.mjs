import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

const actions = read("components/services/service-actions.jsx");

/*
 * The actions column was six icon-only buttons per row — logs, config test, PHP
 * settings, then start/reload, restart, stop — with no text on any of them, and
 * reload and restart adjacent as near-identical circular arrows. A row you had
 * to hover through to read, and on a touch screen could not read at all.
 *
 * One labelled button for the verb that matches the state, everything else
 * behind "…", which is the shape FileRowActions already uses.
 */

test("the row leads with the verb that matches the state", () => {
  // Restart a running unit; start a stopped or failed one. `failed` leads with
  // start because recovery from failed is "bring it up" — restart stays in the
  // menu for when it is not.
  assert.match(
    actions,
    /const PRIMARY = \{ active: "restart", inactive: "start", failed: "start" \}/,
  );
});

test("the primary button carries its word, not just an icon", () => {
  const primary = actions.slice(
    actions.indexOf("{primary ? ("),
    actions.indexOf("{hasMenu ? ("),
  );

  assert.match(
    primary,
    /t\(`actions\.\$\{primary\}`\)/,
    "the button must be labelled",
  );
  assert.ok(
    !/size="icon"/.test(primary),
    "an icon-only primary is the thing this replaced",
  );
});

test("stop is never a bare button on the row", () => {
  // It already asked for confirmation, so it was never one click. What it gains
  // is not sitting one pixel from Restart.
  assert.ok(
    !/PRIMARY = \{[^}]*"stop"/.test(actions),
    "stop must not be any state's primary action",
  );
  assert.match(
    actions,
    /variant=\{action === "stop" \? "destructive" : undefined\}/,
    "and it must read as destructive in the menu",
  );
});

test("a unit that cannot do its state's usual action still gets a button", () => {
  // A protected service that may only be reloaded would otherwise render a row
  // with no action at all, which reads as broken rather than as restricted.
  assert.match(actions, /actions\.includes\(PRIMARY\[service\.status\]\)/);
  assert.match(actions, /: \(actions\[0\] \?\? null\)/);
});

test("the config-test dialog is rendered outside the menu", () => {
  // A <Dialog> inside DropdownMenuContent unmounts the instant the menu closes,
  // so the result would flash and vanish. The menu owns the item; the dialog is
  // its sibling.
  const menu = actions.slice(
    actions.indexOf("<DropdownMenuContent"),
    actions.indexOf("</DropdownMenuContent>"),
  );

  assert.ok(
    !/ConfigTestDialog/.test(menu),
    "the dialog must not live inside the menu",
  );
  assert.match(actions, /<ConfigTestDialog/, "but it must still be rendered");
});

test("the slot scaffolding went with the decision it propped up", () => {
  // Fixed slots existed to keep icons aligned down a column. With one button per
  // row there is no column to align, and a shared component should not keep an
  // option that exists to support an answer that turned out wrong.
  assert.ok(!/reserveSlots/.test(actions), "reserveSlots must be gone");
  assert.ok(!/function Slot\b/.test(actions), "the Slot spacer must be gone");

  for (const file of [
    "components/services/services-cards.jsx",
    "components/services/service-attention-list.jsx",
  ]) {
    assert.ok(
      !/reserveSlots/.test(read(file)),
      `${file} still passes a prop that no longer exists`,
    );
  }
});
