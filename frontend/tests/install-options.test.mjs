import test from "node:test";
import assert from "node:assert/strict";
import {
  allInstalled,
  firstInstallable,
  installOptions,
} from "../lib/runtime/install-options.js";

// The real shapes: the picker gets objects, the page has the installed list.
const OFFERED = [
  { version: "26.8.1", lifecycle: { status: "current" } },
  { version: "25.9.0", lifecycle: { status: "eol" } },
  { version: "24.20.0", lifecycle: { status: "lts" } },
];
const INSTALLED = [{ version: "25.9.0" }, { version: "24.20.0", is_default: true }];

test("an offered version that is already on the server is marked", () => {
  /*
   * The live panel, exactly: Node 25.9.0 and 24.20.0 installed, and both still
   * sitting in the install dropdown looking like versions you do not have.
   * PHP's backend filtered its installed version out and Node's did not, so
   * one dialog behaved two ways depending on which page opened it.
   */
  const options = installOptions(OFFERED, INSTALLED);

  assert.deepEqual(
    options.map((o) => [o.version, o.installed]),
    [["26.8.1", false], ["25.9.0", true], ["24.20.0", true]],
  );
  // Marked, never dropped — a shorter list cannot say WHY a version is absent.
  assert.equal(options.length, OFFERED.length);
  // The rest of the option survives; the lifecycle badge still needs it.
  assert.deepEqual(options[0].lifecycle, { status: "current" });
});

test("plain version strings work as the installed list too", () => {
  const options = installOptions(OFFERED, ["24.20.0"]);
  assert.deepEqual(options.map((o) => o.installed), [false, false, true]);
});

test("matching is exact, so a newer patch of an installed major is still offered", () => {
  // fnm publishes the newest patch of each major. With 24.20.0 on disk and
  // 24.20.1 published, 24.20.1 is a real upgrade — matching by major would
  // have hidden the only way to get it.
  const options = installOptions([{ version: "24.20.1" }], [{ version: "24.20.0" }]);
  assert.equal(options[0].installed, false);
});

test("the dialog opens on something you can actually install", () => {
  // It used to open on `installable[0]`. With the newest version installed
  // that preselects a disabled row: Install is live and does nothing useful.
  assert.equal(firstInstallable(installOptions(OFFERED, INSTALLED)), "26.8.1");
  assert.equal(
    firstInstallable(installOptions(OFFERED, [{ version: "26.8.1" }])),
    "25.9.0",
  );
});

test("everything installed is its own answer, not 'nothing available'", () => {
  const all = installOptions(OFFERED, OFFERED);
  assert.equal(allInstalled(all), true);
  // Nothing left to preselect, which leaves Install disabled — the button says
  // why via `allInstalled`.
  assert.equal(firstInstallable(all), "");

  // An empty offer is the OTHER fact: the index has nothing new. Reporting it
  // as "you have them all" would be a different lie.
  assert.equal(allInstalled(installOptions([], INSTALLED)), false);
  assert.equal(allInstalled(installOptions(OFFERED, [])), false);
});

test("junk in does not throw", () => {
  assert.deepEqual(installOptions(), []);
  assert.deepEqual(installOptions(null, null), []);
  // A malformed option with no version is dropped rather than rendered as a
  // blank, unselectable row.
  assert.deepEqual(installOptions([{ lifecycle: {} }, { version: "8.5" }], []).length, 1);
  assert.equal(firstInstallable(), "");
  assert.equal(allInstalled(), false);
});
