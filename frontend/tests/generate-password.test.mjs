import test from "node:test";
import assert from "node:assert/strict";
import { generatePassword } from "../lib/applications/generate-password.js";

// The bug this guards: every character was drawn uniformly from one 65-char
// alphabet holding only 8 digits, so a 20-char password contained no digit
// (57/65)^20 = 7.2% of the time — and every schema it feeds requires one.
// Measured 7.24% over 200k runs before the fix.
test("every password satisfies the rules we validate it against", () => {
  for (let i = 0; i < 5000; i += 1) {
    const p = generatePassword();
    assert.match(p, /[A-Z]/, `no uppercase: ${p}`);
    assert.match(p, /[a-z]/, `no lowercase: ${p}`);
    assert.match(p, /[0-9]/, `no digit: ${p}`);
    assert.equal(p.length, 20);
  }
});

test("look-alike characters stay out, so a password read off a screen retypes", () => {
  for (let i = 0; i < 2000; i += 1) {
    assert.doesNotMatch(generatePassword(), /[0O1lI]/);
  }
});

test("a shorter password is still exactly the length asked for", () => {
  assert.equal(generatePassword(10).length, 10);
  assert.equal(generatePassword(4).length, 4);
  // Fewer characters than required classes: length wins, it is not padded out.
  assert.equal(generatePassword(2).length, 2);
});

// A guaranteed character placed at a fixed index is a smaller search space.
test("the guaranteed characters are not always in the same position", () => {
  const firstIsUpper = new Set();
  for (let i = 0; i < 500; i += 1) firstIsUpper.add(/[A-Z]/.test(generatePassword()[0]));
  assert.equal(firstIsUpper.size, 2, "first character was always the same class");
});
