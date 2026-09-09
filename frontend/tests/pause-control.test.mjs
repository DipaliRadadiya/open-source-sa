import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { pauseControl } from "../lib/applications/pause-control.js";

const manage = { canManage: true };

test("a served site is offered Pause, a paused one Resume", () => {
  assert.equal(pauseControl({ status: "active", is_disabled: false }, manage), "pause");
  assert.equal(pauseControl({ status: "active", is_disabled: true }, manage), "resume");
});

test("paused wins over status, because pausing does not change status", () => {
  /*
   * The trap this exists to hold shut. `disable()` swaps the vhost and stamps
   * `disabled_at`; it never touches `status`, so a paused site still reads as
   * "active". Ask about status first and the menu offers Pause on a site that
   * is already paused, which the API answers 422 to — the user presses a
   * control that cannot work and is told off for it.
   */
  assert.equal(pauseControl({ status: "active", is_disabled: true }, manage), "resume");
  // And a paused site is never stranded: whatever else is true about it, the
  // way back is offered.
  assert.equal(pauseControl({ status: "installing", is_disabled: true }, manage), "resume");
  assert.equal(pauseControl({ status: "failed", is_disabled: true }, manage), "resume");
});

test("a site that is not being served yet is offered neither", () => {
  for (const status of ["installing", "queued", "failed"]) {
    assert.equal(pauseControl({ status, is_disabled: false }, manage), null);
  }
});

test("read-only access is offered neither", () => {
  assert.equal(pauseControl({ status: "active", is_disabled: false }, { canManage: false }), null);
  assert.equal(pauseControl({ status: "active", is_disabled: true }, { canManage: false }), null);
  // Defaulting to no permission rather than to permission: a caller that
  // forgets the option shows nothing instead of showing an outage button.
  assert.equal(pauseControl({ status: "active", is_disabled: false }), null);
});

test("a missing application never throws", () => {
  assert.equal(pauseControl(null, manage), null);
  assert.equal(pauseControl(undefined, manage), null);
  // A payload with the flag stripped is treated as running, not as paused —
  // the same reading the status badge gives it.
  assert.equal(pauseControl({ status: "active" }, manage), "pause");
});

test("the two calls point at the routes the backend actually registers", () => {
  /*
   * Both were confirmed against a running build: pressing Pause sent
   * POST /api/applications/1/disable and Resume sent .../enable. A typo here
   * would 404 at runtime and nothing in the build or the type system would
   * notice, so the paths are pinned rather than trusted.
   */
  const client = fs.readFileSync(
    new URL("../lib/api/applications.js", import.meta.url),
    "utf8",
  );
  assert.match(
    client,
    /export function disableApplication\(id\) \{\s*return api\.post\(`\/applications\/\$\{id\}\/disable`\);/,
    "disableApplication no longer posts to /applications/{id}/disable",
  );
  assert.match(
    client,
    /export function enableApplication\(id\) \{\s*return api\.post\(`\/applications\/\$\{id\}\/enable`\);/,
    "enableApplication no longer posts to /applications/{id}/enable",
  );
});

test("pausing asks first; resuming does not", () => {
  // Pausing turns real visitors away, so it goes through the shared confirm
  // dialog. Resuming restores normal service and fires straight from the menu —
  // a confirmation there is a click with nothing to decide.
  const menu = fs.readFileSync(
    new URL("../components/applications/application-row-actions.jsx", import.meta.url),
    "utf8",
  );
  assert.match(menu, /setPauseOpen\(true\)/, "Pause no longer opens the confirmation");
  assert.match(menu, /resume\(\);/, "Resume no longer acts directly");

  const dialog = fs.readFileSync(
    new URL("../components/applications/pause-application-dialog.jsx", import.meta.url),
    "utf8",
  );
  // Stays open carrying the reason: a 422 here is usually "someone already
  // paused it in another tab", and that sentence is the API's to give.
  assert.match(dialog, /setError\(apiMessage\(/, "a failed pause no longer explains itself in the dialog");
  // The failure branch specifically must NOT close it — closing would throw
  // away both the reason and the retry, which is what "the modal does nothing"
  // reports are actually describing.
  const failureBranch = dialog.slice(dialog.indexOf("} catch ("), dialog.indexOf("} finally {"));
  assert.match(failureBranch, /setError\(/, "the failure branch no longer records a reason");
  assert.doesNotMatch(failureBranch, /onOpenChange\(false\)/, "a failed pause closes the dialog and loses the reason");
});
