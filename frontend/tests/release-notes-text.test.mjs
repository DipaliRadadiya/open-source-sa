import test from "node:test";
import assert from "node:assert/strict";
import { releaseNotesText } from "../lib/admin/release-notes-text.js";

// The actual body of v1.0.2, and the reason this exists.
test("the release we ship no longer opens with literal asterisks", () => {
  assert.equal(
    releaseNotesText(
      "**Full Changelog**: https://github.com/DipaliRadadiya/open-source-sa/compare/v1.0.1...v1.0.2",
    ),
    "Full Changelog: https://github.com/DipaliRadadiya/open-source-sa/compare/v1.0.1...v1.0.2",
  );
});

test("headings and bullets become text a person would write", () => {
  assert.equal(releaseNotesText("## What's Changed\n- Fixed a thing\n* And another"),
    "What's Changed\n• Fixed a thing\n• And another");
  assert.equal(releaseNotesText("### Deep heading"), "Deep heading");
  // Indentation is the nesting, so it survives.
  assert.equal(releaseNotesText("- one\n  - nested"), "• one\n  • nested");
});

test("a link keeps both its label and its address", () => {
  assert.equal(releaseNotesText("See [the diff](https://example.com/x) for more"),
    "See the diff (https://example.com/x) for more");
  assert.equal(releaseNotesText("[](https://example.com/x)"), "https://example.com/x");
});

test("backticked code loses only the backticks", () => {
  assert.equal(releaseNotesText("Run `npm ci` first"), "Run npm ci first");
});

// The whole point of pairing: a lone marker is a glob or a bullet, not
// emphasis, and deleting it would change what a command means.
test("an unpaired marker is left exactly as written", () => {
  assert.equal(releaseNotesText("rm -rf build/*"), "rm -rf build/*");
  assert.equal(releaseNotesText("2 ** 8 is 256"), "2 ** 8 is 256");
  assert.equal(releaseNotesText("a_variable_name stays"), "a_variable_name stays");
});

test("runs of blank lines collapse, and the edges are trimmed", () => {
  assert.equal(releaseNotesText("\n\nfirst\n\n\n\nsecond\n\n"), "first\n\nsecond");
});

test("anything that is not text yields nothing to show", () => {
  for (const value of [null, undefined, 42, {}]) {
    assert.equal(releaseNotesText(value), "");
  }
});
