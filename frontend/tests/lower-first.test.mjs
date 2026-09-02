import test from "node:test";
import assert from "node:assert/strict";
import { lowerFirst } from "../lib/activity-log/lower-first.js";

// What the feed is built from: "test" + "logged in" + "50 times".
test("an ordinary capitalised label joins mid-sentence", () => {
  assert.equal(lowerFirst("Logged in"), "logged in");
  assert.equal(lowerFirst("Password changed"), "password changed");
  assert.equal(lowerFirst("Role assigned"), "role assigned");
});

// The reason this is guarded rather than a blind toLowerCase on [0].
test("an acronym keeps its capitals", () => {
  assert.equal(lowerFirst("SSH key added"), "SSH key added");
  assert.equal(lowerFirst("PHP settings updated"), "PHP settings updated");
  assert.equal(lowerFirst("API token created"), "API token created");
});

test("text that does not start with a letter is left alone", () => {
  assert.equal(lowerFirst("2FA enabled"), "2FA enabled");
  assert.equal(lowerFirst("· started"), "· started");
  // Scripts without letter case must not be touched at all.
  assert.equal(lowerFirst("लॉग इन किया"), "लॉग इन किया");
});

test("already lowercase text is returned unchanged", () => {
  assert.equal(lowerFirst("logged in"), "logged in");
});

test("nothing usable in, nothing broken out", () => {
  assert.equal(lowerFirst(""), "");
  assert.equal(lowerFirst("A"), "A");
  assert.equal(lowerFirst(null), "");
  assert.equal(lowerFirst(undefined), "");
});
