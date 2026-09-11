import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

/*
 * Save on the edit dialog is gated on `isDirty`, and `setValue` does not touch
 * that flag unless asked. So a control that writes through setValue and forgets
 * `shouldDirty` changes the value, redraws itself, and leaves Save dead.
 *
 * Reported as "on edit cronjob if just change schedule it not enable save
 * button" — and it read as intermittent, because typing in the Custom
 * expression box DOES work: that input is registered normally.
 *
 * Nothing in the type system or the build can see this. The value is correct,
 * the render is correct, and only the button is wrong.
 */

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");

/** Every `form.setValue(...)` call in a file, as source text. */
function setValueCalls(source) {
  const calls = [];
  for (const match of source.matchAll(/form\.setValue\(/g)) {
    let depth = 0;
    // The opening paren is the last character of the match — searching PAST it
    // finds the first paren of the first argument instead.
    const i = match.index + match[0].length - 1;
    let j = i;
    while (j < source.length) {
      if (source[j] === "(") depth += 1;
      else if (source[j] === ")") {
        depth -= 1;
        if (depth === 0) break;
      }
      j += 1;
    }
    calls.push({ text: source.slice(i, j + 1), line: source.slice(0, match.index).split("\n").length });
  }
  return calls;
}

test("the edit dialog still gates Save on dirty — the reason this matters", () => {
  const dialog = read("components/cron-jobs/edit-cronjob-dialog.jsx");
  assert.match(dialog, /disabled=\{isSubmitting \|\| !isDirty\}/);
});

test("every control that writes the schedule marks the form dirty", () => {
  const calls = setValueCalls(read("components/cron-jobs/schedule-field.jsx"));
  assert.ok(calls.length > 0, "the preset picker no longer writes through setValue");
  for (const call of calls) {
    assert.match(call.text, /shouldDirty: true/, `schedule-field.jsx:${call.line} leaves Save disabled`);
  }
});

test("every control that writes the command marks the form dirty", () => {
  /*
   * Four of these, all reachable on an existing job: picking a command
   * template, picking a site (which fills Run as), typing a path, and choosing
   * Custom to clear it. Change only one and Save has to come alive.
   */
  const source = read("components/cron-jobs/command-field.jsx");
  const calls = setValueCalls(source);
  assert.ok(calls.length >= 5, `expected the template/site/path writes, found ${calls.length}`);

  const starterAt = source.indexOf("if (!starter) return;");
  assert.ok(starterAt > 0, "the quick-start effect moved — recheck which calls are seeds");
  const starterLine = source.slice(0, starterAt).split("\n").length;

  for (const call of calls) {
    // The starter effect is the one deliberate exception: it only ever opens
    // the CREATE dialog and fires on mount, not off a control. Marking a form
    // dirty before it has been touched is what makes a leave-guard cry wolf.
    if (call.line > starterLine) {
      assert.doesNotMatch(call.text, /shouldDirty/, `command-field.jsx:${call.line} dirties a form nobody touched`);
      continue;
    }
    assert.match(call.text, /shouldDirty: true/, `command-field.jsx:${call.line} leaves Save disabled`);
  }
});

test("the quick-start seed reaches create only, which is why it may stay clean", () => {
  // If a starter ever reaches the edit dialog, the exception above stops being
  // safe — an edit seeded from a template would silently refuse to save.
  const edit = read("components/cron-jobs/edit-cronjob-dialog.jsx");
  assert.doesNotMatch(edit, /starterKey/, "edit now takes a starter; the seed must dirty too");
  assert.match(read("components/cron-jobs/create-cronjob-dialog.jsx"), /starterKey/);
});
