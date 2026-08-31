import { test } from "node:test";
import assert from "node:assert/strict";
import {
  passwordRules,
  passwordMeetsRules,
  DEFAULT_PASSWORD_POLICY,
} from "../lib/auth/password-rules.js";
import { registerSchema } from "../lib/schemas/auth.js";
import { changePasswordSchema } from "../lib/schemas/account.js";

const ok = (value) => Object.fromEntries(passwordRules(value).map((r) => [r.key, r.ok]));

test("each rule reports on its own", () => {
  assert.deepEqual(ok(""), { length: false, case: false, number: false });
  assert.deepEqual(ok("short1A"), { length: false, case: true, number: true });
  assert.deepEqual(ok("alllowercase1"), { length: true, case: false, number: true });
  assert.deepEqual(ok("ALLUPPERCASE1"), { length: true, case: false, number: true });
  assert.deepEqual(ok("NoDigitsHere"), { length: true, case: true, number: false });
  assert.deepEqual(ok("CorrectHorse1"), { length: true, case: true, number: true });
});

test("a non-string never throws the form away", () => {
  // field.value is undefined on first render.
  assert.deepEqual(ok(undefined), { length: false, case: false, number: false });
  assert.deepEqual(ok(null), { length: false, case: false, number: false });
});

test("exactly ten characters passes — the boundary the copy promises", () => {
  assert.equal(passwordRules("Abcdefghi1")[0].ok, true);
  assert.equal(passwordRules("Abcdefgh1")[0].ok, false);
});

// The point of the shared module: a checklist that says "you're fine" while the
// schema rejects the submit is worse than no checklist.
test("the checklist agrees with both schemas it describes", () => {
  const candidates = [
    "",
    "short1A",
    "alllowercase1",
    "ALLUPPERCASE1",
    "NoDigitsHere",
    "Abcdefghi1",
    "CorrectHorse1",
    "  Padded  1A  ",
  ];

  for (const password of candidates) {
    const expected = passwordMeetsRules(password);

    const register = registerSchema.safeParse({
      name: "A",
      username: "a",
      password,
      password_confirmation: password,
    });
    assert.equal(register.success, expected, `register disagreed on ${JSON.stringify(password)}`);

    const change = changePasswordSchema.safeParse({
      current_password: "whatever",
      password,
      password_confirmation: password,
    });
    assert.equal(change.success, expected, `change disagreed on ${JSON.stringify(password)}`);
  }
});

// --- policy-driven (the server publishes it on GET /basic-info) -------------

test("only the rules the policy asks for are shown", () => {
  const relaxed = { min_length: 8, requires_mixed_case: false, requires_number: false, requires_symbol: false };
  assert.deepEqual(passwordRules("abcdefgh", relaxed).map((r) => r.key), ["length"]);
  // A line that can never fail is noise; one the server does not enforce is a lie.
  assert.equal(passwordMeetsRules("abcdefgh", relaxed), true);
});

test("a symbol requirement appears only when the policy sets it", () => {
  const strict = { min_length: 10, requires_mixed_case: true, requires_number: true, requires_symbol: true };
  assert.equal(passwordRules("CorrectHorse1", strict).some((r) => r.key === "symbol"), true);
  assert.equal(passwordMeetsRules("CorrectHorse1", strict), false);
  assert.equal(passwordMeetsRules("CorrectHorse1!", strict), true);
  // Default policy has it off, so the same password passes there.
  assert.equal(passwordMeetsRules("CorrectHorse1"), true);
});

test("the length rule carries the number it is checking", () => {
  const [length] = passwordRules("", { ...DEFAULT_PASSWORD_POLICY, min_length: 16 });
  assert.equal(length.min, 16);
  assert.equal(passwordMeetsRules("Abcdefghijklmno1", { ...DEFAULT_PASSWORD_POLICY, min_length: 16 }), true);
  assert.equal(passwordMeetsRules("Abcdefghi1", { ...DEFAULT_PASSWORD_POLICY, min_length: 16 }), false);
});

test("a missing or partial policy falls back rather than showing nothing", () => {
  // An older backend sends no password_policy at all.
  assert.deepEqual(passwordRules("", null).map((r) => r.key), ["length", "case", "number"]);
  assert.deepEqual(passwordRules("", {}).map((r) => r.key), ["length", "case", "number"]);
  assert.equal(passwordRules("", { min_length: undefined })[0].min, DEFAULT_PASSWORD_POLICY.min_length);
});
