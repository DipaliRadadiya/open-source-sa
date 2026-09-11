import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { withRuntimeAvailability } from "../lib/applications/runtime-readiness.js";

const root = path.join(import.meta.dirname, "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

const refresh = read("components/applications/runtime-refresh.jsx");
const form = read("components/applications/create-application-form.jsx");

/*
 * Refreshing the runtime versions from inside the create form.
 *
 * Installing a PHP version happens on another screen, so the only way to see it
 * here was a reload — which threw the half-filled form away. Driven end to end
 * in a real build before this file was written: WordPress selected, five fields
 * filled, PHP 8.1 installed behind the page, Refresh pressed. Every value
 * survived (including the generated temporary domain and the chosen version),
 * the select went from ["8.4"] to ["8.4","8.1"], and the toast read
 * "PHP 8.1 is now available."
 */

test("the refresh re-runs the page, it does not fetch the list itself", () => {
  /*
   * `useRefresh` re-renders the server component, so the versions and the
   * site-type availability computed FROM them move together. A client fetch
   * into local state would have updated the select and left the type cards
   * saying "no PHP version this can run on" — a half-refresh reads as a bug.
   */
  assert.match(refresh, /useRefresh/);
  assert.doesNotMatch(refresh, /axios|api\.get|fetch\(/, "a second source for the same list");
});

test("the button sits on both runtime fields, not just PHP", () => {
  // One renderer draws both selects, so Node had the identical problem.
  assert.match(form, /isRuntime \? \(\s*<RuntimeRefresh/);
  assert.match(form, /runtime=\{config\.source === "php_versions" \? "PHP" : "Node\.js"\}/);
});

test("it says what changed, including when nothing did", () => {
  /*
   * A version still installing is not in this list — the page filters to
   * `ready` — so the commonest moment to press this is also the one where
   * nothing appears to happen. Silence there reads as a dead button.
   */
  assert.match(refresh, /versionsAdded/);
  assert.match(refresh, /versionsUnchanged/);
});

test("only our own press is reported on", () => {
  /*
   * `pending` is shared with the rest of the page under NavTransitionProvider.
   * Without a marker for "this button started it", a refresh triggered
   * elsewhere would pop a toast about PHP versions nobody asked about.
   */
  assert.match(refresh, /before\.current === null/);
  assert.match(refresh, /before\.current = versions\.map/);
});

test("every new string resolves in every locale, with its placeholders", () => {
  for (const locale of ["en", "es", "hi"]) {
    const messages = JSON.parse(read(`messages/${locale}.json`));
    const strings = messages.applications.form;

    for (const key of ["refreshVersions", "refreshVersionsHint", "versionsAdded", "versionsUnchanged"]) {
      assert.ok(strings[key], `${locale}: ${key}`);
    }
    // A missing placeholder renders the literal name, or throws on the plural.
    assert.match(strings.refreshVersionsHint, /\{runtime\}/, locale);
    assert.match(strings.versionsUnchanged, /\{runtime\}/, locale);
    assert.match(strings.versionsAdded, /\{runtime\}/, locale);
    assert.match(strings.versionsAdded, /\{versions\}/, locale);
    assert.match(strings.versionsAdded, /\{count, plural,/, locale);
  }
});

test("the same refresh is what un-greys a type blocked by its PHP range", () => {
  /*
   * The other half of the press, and the reason it re-runs the server rather
   * than fetching: PrestaShop runs on 7.2–8.1, so on a box with only 8.4 the
   * card is blocked with the backend's own `runtime` code. Installing 8.1 and
   * refreshing has to make it selectable in the same press — the list and the
   * cards are computed from one fetch, so they cannot disagree.
   */
  const prestashop = {
    name: "prestashop",
    available: true,
    php_version_range: { min: "7.2", max: "8.1" },
  };
  const reason = () => "needs a PHP version it can run on";

  const [blocked] = withRuntimeAvailability(
    [prestashop],
    { phpVersions: [{ version: "8.4" }], nodeVersions: [], failed: false },
    reason,
  );
  assert.equal(blocked.available, false);
  assert.equal(blocked.unavailable_code, "runtime");

  const [freed] = withRuntimeAvailability(
    [prestashop],
    { phpVersions: [{ version: "8.4" }, { version: "8.1" }], nodeVersions: [], failed: false },
    reason,
  );
  assert.equal(freed.available, true);
  assert.equal(freed.unavailable_code, undefined);
});
