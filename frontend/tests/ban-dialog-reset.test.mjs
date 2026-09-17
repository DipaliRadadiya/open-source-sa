import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const dialog = fs.readFileSync("components/fail2ban/ban-ip-dialog.jsx", "utf8");

/*
 * Reported: type an invalid address in Ban Address, get the error, close the
 * dialog, reopen it — and the rejected address is still sitting in the field.
 *
 * `handleOpenChange` cleared the error on close and left the address. Driving
 * it confirmed exactly that split: after reopening, no alert, old value.
 *
 * The second half matters more than the first. The trigger calls `setOpen(true)`
 * itself, so it never passes through `onOpenChange` — clearing only on close
 * leaves every path that skips that handler (a close while `pending`, any
 * future caller flipping `open`) able to reopen onto stale input.
 */

test("opening the dialog clears the fields, not just closing it", () => {
  assert.match(dialog, /function resetFields\(\)/, "no single place resets the form");

  // The trigger must go through a handler that resets, not straight to setOpen.
  assert.match(dialog, /onClick=\{openDialog\}/);
  assert.doesNotMatch(
    dialog,
    /onClick=\{\(\) => setOpen\(true\)\}/,
    "the trigger opens without resetting, so stale input survives a reopen",
  );

  assert.match(dialog, /function openDialog\(\)\s*\{\s*resetFields\(\);/);
});

test("closing clears the address as well as the error", () => {
  // The bug was that these two were not clearing together.
  assert.match(dialog, /if \(!next\) resetFields\(\);/);
  assert.match(dialog, /setIp\(""\);/, "the address is never cleared");
  assert.match(dialog, /setError\(null\);/, "the error is never cleared");
});

test("a successful ban uses the same reset as everything else", () => {
  /*
   * It used to clear the address by hand right there, which is how the close
   * path came to clear a different subset — two places deciding what "empty"
   * means, and they disagreed.
   */
  const code = dialog.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  const success = code.slice(code.indexOf("toast.success"), code.indexOf("} catch"));
  assert.match(success, /resetFields\(\)/, "the success path clears fields its own way again");
});
