import test from "node:test";
import assert from "node:assert/strict";
import {
  describeMode,
  hasPermission,
  isWorldWritable,
  modeParts,
  withPermission,
} from "../lib/files/describe-mode.js";

// `find -printf %m` prints FOUR digits whenever setuid, setgid or the sticky
// bit is set. Every helper here tested `^[0-7]{3}$` and treated those as
// malformed. The worst of it was withPermission falling back to "000".

test("a sticky world-writable directory is what these must handle", () => {
  assert.deepEqual(modeParts("1777"), { special: "1", permissions: "777" });
  assert.deepEqual(modeParts("755"), { special: "", permissions: "755" });
  assert.equal(modeParts("77"), null);
  assert.equal(modeParts("12345"), null);
  assert.equal(modeParts("8777"), null);
  assert.equal(modeParts(null), null);
});

// The bug that could take a site down: tick one box on a 1777 uploads folder
// and the dialog proposed 020, which it would then save.
test("toggling a permission never discards the special-bits digit", () => {
  assert.equal(withPermission("1777", "group", "write", true), "1777");
  assert.equal(withPermission("1777", "other", "write", false), "1775");
  assert.equal(withPermission("4755", "group", "write", true), "4775");
  assert.equal(withPermission("755", "group", "write", true), "775");
});

// The red warning on the file list. 1777 is the single mode most worth it.
test("world-writable is read off the last permission digit", () => {
  for (const mode of ["777", "666", "757", "1777", "4777", "2707"]) {
    assert.equal(isWorldWritable(mode), true, `${mode} should be world-writable`);
  }
  for (const mode of ["755", "644", "600", "1755", "4755", "2775"]) {
    assert.equal(isWorldWritable(mode), false, `${mode} should not be`);
  }
  // Not a mode at all — must not throw or guess.
  assert.equal(isWorldWritable(""), false);
  assert.equal(isWorldWritable(null), false);
});

// Each checkbox read one audience across on a four-digit mode, because the raw
// index 0 was the special-bits digit rather than the owner's.
test("checkboxes read the right audience on a four-digit mode", () => {
  assert.equal(hasPermission("1777", "owner", "read"), true);
  assert.equal(hasPermission("1644", "owner", "write"), true);
  assert.equal(hasPermission("1644", "group", "write"), false);
  assert.equal(hasPermission("644", "group", "write"), false);
  assert.equal(hasPermission("664", "group", "write"), true);
});

test("special bits do not change what an audience may do", () => {
  assert.deepEqual(describeMode("1777"), describeMode("777"));
  assert.deepEqual(describeMode("4755"), describeMode("755"));
  // An in-progress custom entry stays null, which the dialog relies on.
  assert.equal(describeMode("7"), null);
  assert.equal(describeMode("75"), null);
});
