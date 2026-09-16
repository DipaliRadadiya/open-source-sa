import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const form = fs.readFileSync("components/settings/ssh-form.jsx", "utf8");

/*
 * Reported: on the SSH settings page, picking "With a password or an SSH key"
 * left "With an SSH key only" disabled and unselectable until the page was
 * reloaded.
 *
 * The lockout guard — no SSH key on the server means key-only would lock you
 * out — was keyed on `field.value`, the LIVE radio state. So on a server
 * already set to key-only the option started enabled, disabled itself the
 * instant the other option was picked, and could not be picked back. You could
 * leave the choice but never return to it.
 *
 * Reloading appeared to fix it because the form reset to the saved value.
 */

test("the lockout guard reads the saved setting, not the live radio", () => {
  assert.match(
    form,
    /security\?\.has_ssh_key === false &&\s*defaults\.password_authentication/,
    "the guard must key on `defaults`, the value the server actually has",
  );

  /*
   * The exact shape of the bug, in any spelling. `field.value` changes as soon
   * as a radio is clicked, so any guard built on it disables the option the
   * user just left.
   */
  assert.doesNotMatch(
    form,
    /has_ssh_key === false && field\.value/,
    "the guard is back on the live form value, which traps the choice again",
  );
});

test("the guard still exists at all", () => {
  /*
   * Deleting it would also "fix" the report, and would be worse: the API
   * answers 422 on key-only with no key, so the user would discover the
   * lockout risk after confirming rather than before choosing.
   */
  assert.match(form, /signIn\.option\.key\.noKey/, "the no-key reason is no longer shown anywhere");
  assert.match(form, /disabledReason:/, "the option can no longer be blocked at all");
});
