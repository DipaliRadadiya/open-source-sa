import { test } from "node:test";
import assert from "node:assert/strict";
import { symbolicMode } from "../lib/files/describe-mode.js";

test("renders a mode the way ls does, so it can be read rather than decoded", () => {
  assert.equal(symbolicMode("755", "dir"), "drwxr-xr-x");
  assert.equal(symbolicMode("644", "file"), "-rw-r--r--");
  assert.equal(symbolicMode("600", "file"), "-rw-------");
  assert.equal(symbolicMode("777", "dir"), "drwxrwxrwx");
});

test("handles the 4-digit modes find reports for special bits", () => {
  // `find -printf %m` emits the leading digit whenever setuid, setgid or the
  // sticky bit is set. A 3-digit-only reading gave up on exactly the files
  // whose permissions are most worth looking at.
  assert.equal(symbolicMode("4755", "file"), "-rwsr-xr-x");
  assert.equal(symbolicMode("2755", "file"), "-rwxr-sr-x");
  assert.equal(symbolicMode("1777", "dir"), "drwxrwxrwt");
});

test("uppercases a special bit that cannot take effect", () => {
  // setuid without execute is a broken setuid binary, and `ls` distinguishes
  // the two with case. Losing that would make a misconfiguration look
  // identical to a working one.
  assert.equal(symbolicMode("4644", "file"), "-rwSr--r--");
  assert.equal(symbolicMode("1666", "dir"), "drw-rw-rwT");
});

test("returns null for anything that is not a mode, so the caller can fall back", () => {
  // The column shows the raw value rather than a blank when this happens —
  // an unparsed mode is still information, an empty cell is not.
  assert.equal(symbolicMode("", "file"), null);
  assert.equal(symbolicMode(null, "file"), null);
  assert.equal(symbolicMode("89", "file"), null);
  assert.equal(symbolicMode("75", "file"), null);
});
