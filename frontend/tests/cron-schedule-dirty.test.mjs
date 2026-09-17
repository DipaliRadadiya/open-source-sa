import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const field = fs.readFileSync("components/cron-jobs/schedule-field.jsx", "utf8");

/*
 * Reported: changing the schedule on an existing cron job leaves Save disabled
 * with "No changes made".
 *
 * Driving every way of changing it found exactly one dead end. Picking a real
 * preset works, editing the raw expression works, and a job whose expression
 * matches no preset works. Picking "Custom" did not — and that one is not a
 * dirtiness bug at all: Custom is a MODE, not a schedule. It reveals the field
 * and changes nothing, so `isDirty` is honestly false.
 *
 * Which is why the fix is focus, not a forced dirty flag. Arming Save there
 * would write the value the job already has and call it a change.
 */

test("picking a preset dirties the form, or Save can never wake", () => {
  /*
   * setValue leaves `isDirty` alone unless asked, and the edit dialog gates
   * Save on it. This has been reported twice; the flag is the whole fix.
   */
  assert.match(
    field,
    /form\.setValue\("expression",\s*preset\.expression,\s*\{[^}]*shouldDirty:\s*true/s,
    "the preset no longer marks the form dirty, so Save stays dead",
  );
});

test("picking Custom moves the cursor into the expression field", () => {
  // Custom changes nothing by itself, so the only honest way out of that state
  // is to put the caret where the change is made.
  assert.match(field, /focusRawOnClose/);
  assert.match(field, /onCloseAutoFocus=\{\(event\) =>/);
  assert.match(field, /rawFieldRef\.current\?\.focus\(\)/);

  /*
   * It has to be `onCloseAutoFocus`. Radix hands focus back to the trigger when
   * the menu closes, which is after any handler or effect fired by the
   * selection — focusing earlier gets silently undone, which is what the first
   * attempt did.
   */
  assert.match(field, /event\.preventDefault\(\)/);
});

test("the raw input keeps react-hook-form's own ref", () => {
  /*
   * Overwriting `field.ref` with a local one would leave RHF unable to focus
   * the field on a validation error — trading one focus bug for another.
   */
  assert.match(field, /field\.ref\(node\)/);
  assert.match(field, /rawFieldRef\.current = node/);
});

test("Custom is not force-dirtied", () => {
  /*
   * The invariant is that the expression is only written when the chosen preset
   * HAS one, and `custom` is defined with a null expression by the API. So the
   * guard is what keeps Custom from arming Save with a no-op write.
   *
   * A first version of this searched onPreset for "key === CUSTOM" followed by
   * "setValue" anywhere after it — which matches the perfectly correct guarded
   * call a few lines down, and failed on working code. Asserting the guard is
   * the thing that is actually true.
   */
  const code = field.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  const onPreset = code.slice(code.indexOf("function onPreset"), code.indexOf("const hasPresets"));

  assert.match(
    onPreset,
    /if \(preset\?\.expression\)\s*\{/,
    "the expression is written without checking the preset has one",
  );

  // Every setValue inside onPreset must sit inside that guard.
  const guardAt = onPreset.indexOf("if (preset?.expression)");
  const firstSetValue = onPreset.indexOf("setValue");
  assert.ok(
    firstSetValue > guardAt,
    "a setValue runs before the has-an-expression guard, so Custom would write one",
  );
});
