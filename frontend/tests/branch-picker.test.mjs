import test from "node:test";
import assert from "node:assert/strict";
import {
  branchFieldMode,
  branchFieldNotice,
  branchOptions,
} from "../lib/applications/branch-picker.js";

const LINKED = { git_account_id: 7, repository: "acme/site" };
const READY = ["main", "develop"];

test("a linked site with a loaded list gets the picker", () => {
  assert.equal(
    branchFieldMode({ application: LINKED, state: "ready", branches: READY }),
    "picker",
  );
});

test("every way the list can fail falls back to a text box", () => {
  /*
   * The risk this whole helper exists for. On the create form a branch list
   * that will not load blocks a site nobody has made yet. Here it would lock
   * the owner out of a field on a site that is already deploying — and an
   * empty disabled picker reads as "your branch is gone".
   */
  const cases = [
    ["request failed", { application: LINKED, state: "error", branches: [] }],
    ["still loading", { application: LINKED, state: "loading", branches: [] }],
    ["provider returned none", { application: LINKED, state: "ready", branches: [] }],
    ["no linked account", { application: { repository: "acme/site" }, state: "ready", branches: READY }],
    ["no repository", { application: { git_account_id: 7 }, state: "ready", branches: READY }],
    [
      "account deleted or token revoked",
      { application: { ...LINKED, git_account_missing: true }, state: "ready", branches: READY },
    ],
    ["nothing at all", {}],
    ["no argument", undefined],
  ];

  for (const [why, input] of cases) {
    assert.equal(branchFieldMode(input), "text", `${why} must stay editable`);
  }
});

test("only a real attempt that failed says anything", () => {
  // A public repository has no account to list branches with. It is not broken,
  // and "branches could not be loaded" would invent a fault on a healthy site.
  assert.equal(branchFieldNotice({ application: {}, state: "error" }), null);
  assert.equal(
    branchFieldNotice({ application: { repository: "acme/site" }, state: "error" }),
    null,
  );

  assert.equal(branchFieldNotice({ application: LINKED, state: "loading" }), "loading");
  assert.equal(branchFieldNotice({ application: LINKED, state: "error" }), "error");
  assert.equal(branchFieldNotice({ application: LINKED, state: "empty" }), "empty");
  assert.equal(branchFieldNotice({ application: LINKED, state: "ready" }), null);

  // A revoked token is its own message: it names the thing to repair, rather
  // than blaming the branch list.
  assert.equal(
    branchFieldNotice({ application: { ...LINKED, git_account_missing: true }, state: "ready" }),
    "unlinked",
  );
});

test("the saved branch survives being deleted upstream", () => {
  // Otherwise the picker shows `main` while the form still holds `release/2.0`
  // — a silent change to what the next deploy builds.
  assert.deepEqual(branchOptions(["main", "develop"], "release/2.0"), [
    { value: "release/2.0", label: "release/2.0" },
    { value: "main", label: "main" },
    { value: "develop", label: "develop" },
  ]);
});

test("a branch already in the list is not duplicated", () => {
  assert.deepEqual(
    branchOptions([{ name: "main" }, { name: "develop" }], "main").map((o) => o.value),
    ["main", "develop"],
  );
});

test("both shapes of branch, and junk, are handled", () => {
  assert.deepEqual(branchOptions([{ name: "main" }, "develop"]).map((o) => o.value), [
    "main",
    "develop",
  ]);
  assert.deepEqual(branchOptions([{}, null, "main"]).map((o) => o.value), ["main"]);
  assert.deepEqual(branchOptions(), []);
  assert.deepEqual(branchOptions(null, null), []);
});
