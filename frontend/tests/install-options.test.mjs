import test from "node:test";
import assert from "node:assert/strict";
import {
  allInstalled,
  firstInstallable,
  installOptions,
  resolveVersion,
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

test("the picker recovers when its choice leaves the list", () => {
  /*
   * The blank Version field. Starting an install moves that version OUT of
   * `installable` — the API reports it under `versions` as "installing" — while
   * the dialog stays mounted holding it in state. A <Select> whose value
   * matches no item renders an EMPTY trigger, so the next person to open the
   * dialog found a blank field and an Install button that submitted nothing.
   *
   * Both the old code and the first version of this file initialised the
   * selection once and never looked at it again, so neither noticed.
   */
  const before = installOptions(OFFERED, INSTALLED);
  assert.equal(resolveVersion(null, before), "26.8.1");

  // 26.8.1 starts installing and drops out of the offered list. 22.x is still
  // installable, so the picker must land on THAT rather than going blank.
  const offeredDuring = [...OFFERED.slice(1), { version: "22.23.2", lifecycle: {} }];
  const during = installOptions(offeredDuring, INSTALLED);
  assert.equal(resolveVersion("26.8.1", during), "22.23.2", "must fall back, not blank");

  // ...and once it finishes it comes back as installed, which is also not
  // selectable — the same fallback has to cover that.
  const after = installOptions(
    [...OFFERED, { version: "22.23.2", lifecycle: {} }],
    [...INSTALLED, { version: "26.8.1" }],
  );
  assert.equal(resolveVersion("26.8.1", after), "22.23.2");
});

test("a choice that is still installable is left alone", () => {
  // Reconciling must not fight the user: re-deriving unconditionally would
  // snap the picker back to the first option on every render.
  const options = installOptions(OFFERED, INSTALLED);
  assert.equal(resolveVersion("26.8.1", options), "26.8.1");
});

test("nothing selectable resolves to empty, which disables Install", () => {
  assert.equal(resolveVersion("8.4", installOptions(OFFERED, OFFERED)), "");
  assert.equal(resolveVersion(null, []), "");
  assert.equal(resolveVersion(undefined, undefined), "");
});

test("a failed install is not installed, so it can be retried", () => {
  /*
   * Reported: PHP 8.2 fails, the page says "Install failed", and the Install
   * Version dropdown still marks 8.2 as Installed — which greys it out and
   * closes the one obvious way to try again.
   *
   * The row exists so the page can report the failure and offer to clear it
   * up. It does not mean the version is on the server.
   */
  const options = installOptions(
    [{ version: "8.2" }, { version: "8.3" }],
    [{ version: "8.2", status: "failed" }, { version: "8.3", status: "ready" }],
  );
  assert.equal(options.find((o) => o.version === "8.2").installed, false);
  assert.equal(options.find((o) => o.version === "8.3").installed, true);
  // And so the picker opens on it rather than on nothing.
  assert.equal(firstInstallable(options), "8.2");
});

test("a version mid-install still counts, so apt is not run twice", () => {
  const options = installOptions(
    [{ version: "8.4" }, { version: "8.5" }],
    [{ version: "8.4", status: "installing" }, { version: "8.5", status: "removing" }],
  );
  assert.equal(options.find((o) => o.version === "8.4").installed, true);
  assert.equal(options.find((o) => o.version === "8.5").installed, true);
});

test("a plain list of version strings still works", () => {
  // Node passes strings rather than rows in some callers; a string carries no
  // status and must keep counting as installed.
  const options = installOptions([{ version: "22" }, { version: "24" }], ["22"]);
  assert.equal(options.find((o) => o.version === "22").installed, true);
  assert.equal(options.find((o) => o.version === "24").installed, false);
});
